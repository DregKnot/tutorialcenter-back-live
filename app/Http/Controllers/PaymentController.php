<?php

namespace App\Http\Controllers;

use App\Models\CoursesEnrollment;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Student;
use App\Services\AdminNotificationService;
use App\Services\BankTransferNotificationService;
use App\Services\ExamPreparationAchievementService;
use App\Services\PaystackService;
use App\Services\StudentNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    // Public: Store a new payment
    public function store(Request $request)
    {
        // Validation must run outside the try/catch so a ValidationException
        // still produces a 422 (not a masked 500) for the client.
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_enrollment_id' => 'required|exists:courses_enrollments,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:card,bank_transfer,ussd,wallet,manual',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
            'gateway' => 'nullable|string',
            'status' => 'required|in:pending,successful,failed,cancelled,refunded',
            // A single gateway reference can legitimately cover several enrollments
            // (one payment, many courses), so uniqueness is per enrollment.
            'gateway_reference' => [
                'nullable',
                'string',
                Rule::unique('payments', 'gateway_reference')
                    ->where(fn ($query) => $query->where('course_enrollment_id', $request->input('course_enrollment_id'))),
            ],
            'meta' => 'nullable|array',
            'paid_at' => 'nullable|date',
        ]);

        // This route is public because registration pays before login, so a
        // client must never be able to settle its own payment: marking a row
        // "successful" activates the enrollment for free. Only an authenticated
        // admin may record a settlement or refund; everyone else may only create
        // or update a non-settling row.
        if (in_array($validated['status'], ['successful', 'refunded'], true)) {
            $actor = auth('sanctum')->user();
            $isAdmin = $actor instanceof Staff && strtolower((string) $actor->role) === 'admin';

            if (!$isAdmin) {
                Log::warning('Blocked unprivileged attempt to set a settling payment status', [
                    'status' => $validated['status'],
                    'student_id' => $validated['student_id'],
                    'course_enrollment_id' => $validated['course_enrollment_id'],
                    'actor_id' => $actor?->id,
                    'actor_type' => $actor ? get_class($actor) : null,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'message' => 'Only an administrator can record a settled or refunded payment.',
                ], 403);
            }
        }

        $enrollment = CoursesEnrollment::withTrashed()->find($validated['course_enrollment_id']);

        if ((int) $enrollment->student_id !== (int) $validated['student_id']) {
            return response()->json([
                'message' => 'The payment does not belong to the enrollment student.',
            ], 422);
        }

        // One row per (reference, enrollment). Re-posting an already-confirmed
        // payment is a no-op; a pending/failed row is upgraded in place below.
        $existing = !empty($validated['gateway_reference'])
            ? Payment::where('gateway_reference', $validated['gateway_reference'])
                ->where('course_enrollment_id', $enrollment->id)
                ->first()
            : null;

        if ($existing && $existing->status === 'successful') {
            return response()->json([
                'message' => 'Payment already recorded for this enrollment.',
                'payment' => $existing,
                'new_achievements' => [],
            ], 200);
        }

        try {
            $payment = DB::transaction(function () use ($validated, $enrollment, $existing) {
                if ($existing) {
                    $existing->update(array_merge($validated, [
                        'meta' => array_merge(
                            $existing->meta ?? [],
                            (array) ($validated['meta'] ?? [])
                        ),
                    ]));
                    $payment = $existing->fresh();
                } else {
                    $payment = Payment::create($validated);
                }

                // Only a confirmed payment may activate the enrollment. A pending
                // or failed row must never unlock course access.
                if ($payment->status === 'successful') {
                    $enrollment->update(['status' => 'active']);
                }

                return $payment;
            });

            $newAchievements = $payment->status === 'successful'
                ? app(ExamPreparationAchievementService::class)->evaluatePayment($payment)
                : [];

            return response()->json([
                'message' => 'Payment created successfully.',
                'payment' => $payment,
                'new_achievements' => collect($newAchievements)
                    ->map(function ($award) {
                        $award->loadMissing('achievement');

                        return [
                            'id' => $award->id,
                            'code' => $award->achievement?->code,
                            'name' => $award->achievement?->name,
                            'category' => $award->achievement?->category,
                            'type' => $award->achievement?->type,
                            'tier' => $award->tier,
                            'awarded_at' => $award->awarded_at,
                        ];
                    })->values()->all(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create payment.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // Student: View my payments
    public function myPayments(Request $request)
    {
        try {
            $studentId = $request->user()->id;
            $payments = Payment::with(['enrollment' => function ($query) {
                $query->withTrashed()->with('course');
            }])->where('student_id', $studentId)->latest()->get();
            $paymentsData = $payments->map(function ($payment) {
                return [
                    ...$payment->toArray(),
                    'course_title' => optional($payment->enrollment?->course)->title ?? null,
                ];
            });
            return response()->json([
                'payments' => $paymentsData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve payments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Admin: View all payments with filters
    public function index(Request $request)
    {
        try {
            $query = Payment::with(['student', 'enrollment.course']);

            if ($request->filled('student_id')) {
                $query->where('student_id', $request->student_id);
            }

            if ($request->filled('course_id')) {
                $query->whereHas('enrollment', function ($q) use ($request) {
                    $q->where('course_id', $request->course_id);
                });
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $payments = $query->latest()->paginate(20);
            return response()->json([
                'payments' => $payments,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve payments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Admin: Find payments and enrollments that may need registration recovery or completion
    public function searchRegistrationRecovery(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
        ]);

        $search = !empty($validated['search']) ? trim($validated['search']) : null;

        $paymentsQuery = Payment::with(['student', 'enrollment.course', 'enrollment.subjects.subject']);

        if ($search) {
            // Admin is searching explicitly for a reference, payment ID, or student
            $paymentsQuery->where(function ($query) use ($search) {
                $query->where('gateway_reference', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }

                $query->orWhereHas('student', function ($studentQuery) use ($search) {
                    $studentQuery->where('email', 'like', "%{$search}%")
                        ->orWhere('tel', 'like', "%{$search}%")
                        ->orWhere('firstname', 'like', "%{$search}%")
                        ->orWhere('surname', 'like', "%{$search}%");

                    if (ctype_digit($search)) {
                        $studentQuery->orWhere('id', (int) $search);
                    }
                });
            });
        } else {
            // Default view: fetch actionable payments needing triage
            // 1. Pending or failed status
            // 2. Orphaned payment (no course enrollment)
            // 3. Payment where enrollment has no course or no subjects
            $paymentsQuery->where(function ($query) {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhereNull('course_enrollment_id')
                    ->orWhereDoesntHave('enrollment')
                    ->orWhereHas('enrollment', function ($q) {
                        $q->whereNull('course_id')
                            ->orWhereDoesntHave('subjects');
                    });
            });
        }

        $payments = $paymentsQuery->latest()->limit(50)->get();

        $paymentStudentIds = $payments->pluck('student_id')->filter()->unique()->values()->all();

        // Also fetch students who signed up but have incomplete registration
        // (no active course enrollment, or active enrollment with 0 subjects)
        $incompleteQuery = Student::with(['courseEnrollments.course', 'courseEnrollments.subjects.subject'])
            ->whereNotIn('id', $paymentStudentIds)
            ->where(function ($query) {
                $query->whereDoesntHave('courseEnrollments', function ($q) {
                    $q->where('status', 'active');
                })
                ->orWhereHas('courseEnrollments', function ($q) {
                    $q->where('status', 'active')
                        ->whereDoesntHave('subjects');
                });
            });

        if ($search) {
            $incompleteQuery->where(function ($query) use ($search) {
                $query->where('email', 'like', "%{$search}%")
                    ->orWhere('tel', 'like', "%{$search}%")
                    ->orWhere('firstname', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        $incompleteStudents = $incompleteQuery->latest()->limit(50)->get();

        return response()->json([
            'message' => ($payments->isEmpty() && $incompleteStudents->isEmpty())
                ? 'No actionable payment or incomplete registration was found.'
                : 'Actionable recovery and incomplete registration records retrieved successfully.',
            'payments' => $payments,
            'incomplete_students' => $incompleteStudents,
        ]);
    }

    // Admin: Complete an enrollment after independently confirming a recorded payment
    public function completeRegistrationRecovery(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'gateway_reference' => 'required|string|max:255',
            'reason' => 'required|string|min:5|max:1000',
        ]);

        try {
            $result = DB::transaction(function () use ($request, $payment, $validated) {
                $lockedPayment = Payment::query()
                    ->with(['enrollment.course', 'student'])
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                if (is_null($lockedPayment->gateway_reference)) {
                    abort(422, 'This payment has no gateway reference to confirm.');
                }

                if (!hash_equals($lockedPayment->gateway_reference, $validated['gateway_reference'])) {
                    abort(422, 'The supplied gateway reference does not match this payment.');
                }

                if (in_array($lockedPayment->status, ['cancelled', 'refunded'], true)) {
                    abort(422, "A {$lockedPayment->status} payment cannot be used for registration recovery.");
                }

                $enrollment = CoursesEnrollment::query()
                    ->lockForUpdate()
                    ->findOrFail($lockedPayment->course_enrollment_id);

                if ((int) $lockedPayment->student_id !== (int) $enrollment->student_id) {
                    abort(422, 'The payment does not belong to the enrollment student.');
                }

                if (abs((float) $lockedPayment->amount - (float) $enrollment->cost) > 0.01) {
                    abort(422, 'The payment amount does not match the enrollment cost.');
                }

                $alreadyCompleted = $lockedPayment->status === 'successful'
                    && $enrollment->status === 'active';

                if (!$alreadyCompleted) {
                    $lockedPayment->update([
                        'status' => 'successful',
                        'paid_at' => $lockedPayment->paid_at ?? now(),
                        'meta' => array_merge($lockedPayment->meta ?? [], [
                            'registration_recovery' => [
                                'completed_by_staff_id' => $request->user()->id,
                                'reason' => $validated['reason'],
                                'completed_at' => now()->toIso8601String(),
                                'confirmation_type' => 'admin_confirmed',
                            ],
                        ]),
                    ]);

                    $enrollment->update(['status' => 'active']);
                }

                return [
                    'already_completed' => $alreadyCompleted,
                    'payment' => $lockedPayment->fresh(['student', 'enrollment.course']),
                ];
            });

            return response()->json([
                'message' => $result['already_completed']
                    ? 'This registration was already completed.'
                    : 'Registration recovery completed successfully.',
                'payment' => $result['payment'],
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Registration recovery failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Public / Authenticated: Verify Paystack payment atomically and activate courses.
     */
    public function verifyPaystackPayment(Request $request, PaystackService $paystackService)
    {
        $validated = $request->validate([
            'reference' => 'required|string',
            'fallback_metadata' => 'nullable|array',
        ]);

        $reference = trim($validated['reference']);

        try {
            // 1. Verify with Paystack server-to-server
            $verifyResult = $paystackService->verifyTransaction($reference);

            if (!$verifyResult['status'] || empty($verifyResult['data'])) {
                // SECURITY HARDENING: Fallback is strictly disallowed in production
                $isLocalOrTesting = app()->environment('local', 'testing') || config('app.env') === 'local';
                $isMockAllowed = $isLocalOrTesting && (
                    str_starts_with($reference, 'TCA_') || 
                    str_starts_with($reference, 'TCR_') ||
                    str_starts_with($reference, 'TC-') ||
                    str_starts_with($reference, 'TC-REN-') ||
                    empty(config('services.paystack.secret_key')) ||
                    config('services.paystack.mock_fallback', false)
                );

                if ($isMockAllowed && !empty($validated['fallback_metadata']) && (!empty($validated['fallback_metadata']['courses']) || !empty($validated['fallback_metadata']['student_id']) || !empty($validated['fallback_metadata']['course_id']))) {
                    \Illuminate\Support\Facades\Log::info('Local mock verification used for reference', ['reference' => $reference]);
                    $price = (float) (
                        $validated['fallback_metadata']['price'] 
                        ?? ($validated['fallback_metadata']['courses'][0]['price'] ?? 10000)
                    );
                    $mockData = [
                        'reference' => $reference,
                        'amount' => $price * 100,
                        'status' => 'success',
                        'paid_at' => now()->toIso8601String(),
                        'channel' => 'card',
                        'metadata' => $validated['fallback_metadata'],
                    ];
                    $processResult = $paystackService->processSuccessfulPayment(
                        $mockData,
                        $validated['fallback_metadata']
                    );

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment verified (Development Mode) and courses activated.',
                        'payments' => $processResult['payments'],
                    ], 200);
                }

                return response()->json([
                    'success' => false,
                    'message' => $verifyResult['message'] ?? 'Payment verification failed on Paystack.',
                ], 400);
            }

            // 2. Process and activate course in database atomically
            $processResult = $paystackService->processSuccessfulPayment(
                $verifyResult['data'],
                $validated['fallback_metadata'] ?? []
            );

            return response()->json([
                'success' => true,
                'message' => $processResult['already_processed']
                    ? 'Payment was already verified and activated.'
                    : 'Payment verified and courses activated successfully.',
                'payments' => $processResult['payments'],
            ], 200);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('verifyPaystackPayment error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment verification.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Direct Bank Transfer
    |--------------------------------------------------------------------------
    | Manual flow: the student transfers to the bank using the short reference,
    | then taps "I have paid". An admin checks the bank/WhatsApp receipt and
    | confirms, which activates the enrollment.
    */

    // Public: Start (or resume) a bank-transfer payment for an enrollment.
    public function initiateBankTransfer(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_enrollment_id' => 'required|exists:courses_enrollments,id',
        ]);

        $enrollment = CoursesEnrollment::withTrashed()->find($validated['course_enrollment_id']);

        if ((int) $enrollment->student_id !== (int) $validated['student_id']) {
            return response()->json([
                'message' => 'The enrollment does not belong to this student.',
            ], 422);
        }

        // The enrollment cost is authoritative; never trust a client amount.
        if ((float) $enrollment->cost <= 0) {
            return response()->json([
                'message' => 'This enrollment has no payable amount.',
            ], 422);
        }

        if (Payment::where('course_enrollment_id', $enrollment->id)->where('status', 'successful')->exists()) {
            return response()->json([
                'message' => 'This enrollment is already paid.',
            ], 409);
        }

        // Reuse the open transfer (and its token) instead of piling up rows.
        $payment = Payment::where('course_enrollment_id', $enrollment->id)
            ->where('payment_method', 'bank_transfer')
            ->where('gateway', 'bank')
            ->whereIn('status', ['pending', 'failed'])
            ->latest()
            ->first();

        $created = false;

        if ($payment) {
            $accessToken = ($payment->meta ?? [])['bank_transfer']['access_token'] ?? null;
        } else {
            $accessToken = Str::random(48);

            $payment = Payment::create([
                'student_id' => $enrollment->student_id,
                'course_enrollment_id' => $enrollment->id,
                'amount' => $enrollment->cost,
                'currency' => 'NGN',
                'payment_method' => 'bank_transfer',
                'gateway' => 'bank',
                'status' => 'pending',
                'billing_cycle' => $enrollment->billing_cycle,
                'gateway_reference' => $this->generateBankTransferReference(),
                'meta' => [
                    'bank_transfer' => [
                        'access_token' => $accessToken,
                        'initiated_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

            $created = true;
        }

        return response()->json([
            'message' => $created
                ? 'Bank transfer initiated. Use the reference as your transfer narration.'
                : 'An open bank transfer already exists for this enrollment.',
            'payment_id' => $payment->id,
            'reference' => $payment->gateway_reference,
            'access_token' => $accessToken,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'state' => $this->bankTransferState($payment),
        ], $created ? 201 : 200);
    }

    // Public (reference + token): the student confirms they have paid.
    public function claimBankTransferPaid(Request $request, string $reference)
    {
        $validated = $request->validate([
            'access_token' => 'required|string',
            'paid_from_account_name' => 'nullable|string|max:255',
            'amount_paid' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($reference, $validated) {
            $payment = Payment::where('gateway_reference', $reference)
                ->where('payment_method', 'bank_transfer')->where('gateway', 'bank')
                ->lockForUpdate()->first();

            if (!$payment) {
                return response()->json(['message' => 'Bank transfer payment not found.'], 404);
            }

            if (!$this->bankTransferTokenMatches($payment, $validated['access_token'])) {
                return response()->json(['message' => 'Invalid payment token.'], 403);
            }

            if (in_array($payment->status, ['successful', 'cancelled', 'refunded'], true)) {
                return response()->json([
                    'message' => "This payment is {$payment->status} and cannot be claimed.",
                ], 422);
            }

            if ($this->bankTransferState($payment) === 'awaiting_confirmation') {
                return response()->json(['message' => 'Your claim is already awaiting confirmation.', 'reference' => $payment->gateway_reference, 'state' => 'awaiting_confirmation']);
            }

            $meta = $payment->meta ?? [];
            $bankTransfer = $meta['bank_transfer'] ?? [];

            $payment->update([
                // A rejected transfer that is claimed again goes back into the queue.
                'status' => 'pending',
                'meta' => array_merge($meta, [
                    'bank_transfer' => array_merge($bankTransfer, [
                        'paid_from_account_name' => $validated['paid_from_account_name'] ?? null,
                        'amount_paid' => isset($validated['amount_paid']) ? (float) $validated['amount_paid'] : null,
                        'note' => $validated['note'] ?? null,
                        'claimed_paid_at' => now()->toIso8601String(),
                        'review' => null,
                    ]),
                ]),
            ]);

            app(BankTransferNotificationService::class)->send($payment, 'claimed');

            AdminNotificationService::notify(
                'bank_transfer_payment_claimed',
                "Student {$payment->student_id} marked {$payment->gateway_reference} (NGN {$payment->amount}) as paid.",
                [
                    'payment_id' => $payment->id,
                    'reference' => $payment->gateway_reference,
                    'student_id' => $payment->student_id,
                ]
            );

            return response()->json([
                'message' => 'Payment noted. An admin will confirm it shortly.',
                'reference' => $payment->gateway_reference,
                'state' => $this->bankTransferState($payment->fresh()),
            ]);
        });
    }

    // Public (reference + token): check the status of a bank transfer.
    public function bankTransferStatus(Request $request, string $reference)
    {
        $payment = $this->findBankTransfer($reference);

        if (!$payment) {
            return response()->json(['message' => 'Bank transfer payment not found.'], 404);
        }

        $token = (string) ($request->query('token') ?? $request->header('X-Payment-Token'));

        if (!$this->bankTransferTokenMatches($payment, $token)) {
            return response()->json(['message' => 'Invalid payment token.'], 403);
        }

        $bankTransfer = ($payment->meta ?? [])['bank_transfer'] ?? [];

        return response()->json([
            'reference' => $payment->gateway_reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'state' => $this->bankTransferState($payment),
            'claimed_paid_at' => $bankTransfer['claimed_paid_at'] ?? null,
            'review' => $bankTransfer['review'] ?? null,
        ]);
    }

    // Admin: list bank transfers for review.
    public function adminBankTransfers(Request $request)
    {
        $state = $request->input('state');

        $query = Payment::with(['student', 'enrollment.course'])
            ->where('payment_method', 'bank_transfer')
            ->where('gateway', 'bank');

        // Admins arrive here from a WhatsApp message carrying the reference.
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($q) use ($search) {
                $q->where('gateway_reference', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($studentQuery) use ($search) {
                        $studentQuery->where('firstname', 'like', "%{$search}%")
                            ->orWhere('surname', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('tel', 'like', "%{$search}%");
                    });
            });
        }

        switch ($state) {
            case 'awaiting_confirmation':
                $query->where('status', 'pending')->whereNotNull('meta->bank_transfer->claimed_paid_at');
                break;
            case 'initiated':
                $query->where('status', 'pending')->whereNull('meta->bank_transfer->claimed_paid_at');
                break;
            case 'approved':
                $query->where('status', 'successful');
                break;
            case 'rejected':
                $query->where('status', 'failed');
                break;
            case 'cancelled':
                $query->whereIn('status', ['cancelled', 'refunded']);
                break;
        }

        $payments = $query->orderBy('created_at')->paginate(20);

        return response()->json([
            'payments' => $payments,
            'state' => $state,
        ]);
    }

    // Admin: confirm a bank transfer and activate the enrollment.
    public function approveBankTransfer(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'confirmed_amount' => 'required|numeric|min:0.01',
            'bank_reference' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:1000',
            'paid_at' => 'nullable|date',
        ]);

        if ($payment->payment_method !== 'bank_transfer' || $payment->gateway !== 'bank') {
            return response()->json(['message' => 'This payment is not a bank transfer.'], 422);
        }

        try {
            $result = DB::transaction(function () use ($payment, $validated, $request) {
                $locked = Payment::lockForUpdate()->findOrFail($payment->id);

                if ($locked->status === 'successful') {
                    return ['already' => true, 'payment' => $locked->fresh(['student', 'enrollment.course'])];
                }

                if (in_array($locked->status, ['cancelled', 'refunded'], true)) {
                    abort(422, "A {$locked->status} payment cannot be approved.");
                }

                $enrollment = CoursesEnrollment::withTrashed()->lockForUpdate()->findOrFail($locked->course_enrollment_id);

                if ((int) $enrollment->student_id !== (int) $locked->student_id) {
                    abort(422, 'The payment does not belong to the enrollment student.');
                }

                // Never create a second settled payment for one enrollment. If the
                // student already paid via Paystack (or another transfer) while this
                // one sat in the queue, approving would double-count the money.
                $conflictingPayment = Payment::where('course_enrollment_id', $locked->course_enrollment_id)
                    ->where('id', '!=', $locked->id)
                    ->where('status', 'successful')
                    ->first();

                if ($conflictingPayment) {
                    abort(422, sprintf(
                        'This enrollment is already settled by %s (%s). Reject this transfer instead of approving, and refund if the student also transferred.',
                        $conflictingPayment->gateway_reference,
                        $conflictingPayment->payment_method
                    ));
                }

                if (abs((float) $locked->amount - (float) $validated['confirmed_amount']) > 0.01) {
                    abort(422, sprintf(
                        'Confirmed amount does not match. Expected %.2f, got %.2f.',
                        (float) $locked->amount,
                        (float) $validated['confirmed_amount']
                    ));
                }

                $meta = $locked->meta ?? [];
                $bankTransfer = $meta['bank_transfer'] ?? [];

                $paidAt = !empty($validated['paid_at'])
                    ? \Carbon\Carbon::parse($validated['paid_at'])
                    : (!empty($bankTransfer['claimed_paid_at'])
                        ? \Carbon\Carbon::parse($bankTransfer['claimed_paid_at'])
                        : now());

                $locked->update([
                    'status' => 'successful',
                    'paid_at' => $paidAt,
                    'meta' => array_merge($meta, [
                        'bank_transfer' => array_merge($bankTransfer, [
                            'review' => [
                                'action' => 'approved',
                                'by_staff_id' => $request->user()->id,
                                'reason' => $validated['reason'] ?? null,
                                'confirmed_amount' => (float) $validated['confirmed_amount'],
                                'bank_reference' => $validated['bank_reference'] ?? null,
                                'at' => now()->toIso8601String(),
                            ],
                        ]),
                    ]),
                ]);

                $enrollment->update(['status' => 'active']);
                app(BankTransferNotificationService::class)->send($locked, 'approved');

                return ['already' => false, 'payment' => $locked->fresh(['student', 'enrollment.course'])];
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to approve bank transfer.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        if (!$result['already']) {
            try {
                app(ExamPreparationAchievementService::class)->evaluatePayment($result['payment']);
            } catch (\Throwable $e) {
                report($e);
            }

            StudentNotificationService::notify($result['payment']->student, 'bank transfer approved', [
                'reference' => $result['payment']->gateway_reference,
                'amount' => $result['payment']->amount,
            ]);
        }

        return response()->json([
            'message' => $result['already']
                ? 'This bank transfer was already approved.'
                : 'Bank transfer approved and enrollment activated.',
            'payment' => $result['payment'],
        ]);
    }

    // Admin: reject a bank transfer so the student can resubmit.
    public function rejectBankTransfer(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        if ($payment->payment_method !== 'bank_transfer' || $payment->gateway !== 'bank') {
            return response()->json(['message' => 'This payment is not a bank transfer.'], 422);
        }

        return DB::transaction(function () use ($payment, $request, $validated) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if (in_array($payment->status, ['successful', 'cancelled', 'refunded'], true)) {
                return response()->json([
                    'message' => 'This payment is already settled or closed and cannot be rejected.',
                ], 422);
            }

            if ($this->bankTransferState($payment) === 'rejected') {
                return response()->json(['message' => 'This bank transfer was already rejected.', 'payment' => $payment]);
            }

            $meta = $payment->meta ?? [];
            $bankTransfer = $meta['bank_transfer'] ?? [];

            $payment->update([
                'status' => 'failed',
                'meta' => array_merge($meta, [
                    'bank_transfer' => array_merge($bankTransfer, [
                        'review' => [
                            'action' => 'rejected',
                            'by_staff_id' => $request->user()->id,
                            'reason' => $validated['reason'],
                            'at' => now()->toIso8601String(),
                        ],
                    ]),
                ]),
            ]);

            app(BankTransferNotificationService::class)->send($payment, 'rejected');
            $payment->loadMissing('student');
            StudentNotificationService::notify($payment->student, 'bank transfer rejected', [
                'reference' => $payment->gateway_reference,
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'message' => 'Bank transfer rejected. The student can upload a new receipt.',
                'payment' => $payment->fresh(['student', 'enrollment.course']),
            ]);
        });
    }

    public function resendBankTransferReceipt(Request $request, Payment $payment)
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->payment_method !== 'bank_transfer' || $payment->gateway !== 'bank' || $payment->status !== 'successful') {
                return response()->json(['message' => 'Only approved bank transfers have receipts.'], 422);
            }
            if (! filter_var($payment->student?->email, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['message' => 'The student does not have a valid email address.'], 422);
            }
            app(BankTransferNotificationService::class)->send($payment, 'approved');
            return response()->json(['message' => 'Receipt email sent.']);
        });
    }

    // Derived queue state for a bank transfer payment.
    protected function bankTransferState(Payment $payment): string
    {
        $bankTransfer = ($payment->meta ?? [])['bank_transfer'] ?? [];

        if ($payment->status === 'successful') {
            return 'approved';
        }

        if ($payment->status === 'failed' && ($bankTransfer['review']['action'] ?? null) === 'rejected') {
            return 'rejected';
        }

        // A transfer that was abandoned for another method (e.g. the student
        // completed Paystack) is closed out, never left looking "awaiting".
        if (in_array($payment->status, ['cancelled', 'refunded'], true)) {
            return $payment->status;
        }

        if (!empty($bankTransfer['claimed_paid_at'])) {
            return 'awaiting_confirmation';
        }

        return 'initiated';
    }

    protected function findBankTransfer(string $reference): ?Payment
    {
        return Payment::where('gateway_reference', $reference)
            ->where('payment_method', 'bank_transfer')
            ->where('gateway', 'bank')
            ->first();
    }

    protected function bankTransferTokenMatches(Payment $payment, ?string $token): bool
    {
        $stored = ($payment->meta ?? [])['bank_transfer']['access_token'] ?? null;

        if (empty($stored) || empty($token)) {
            return false;
        }

        return hash_equals((string) $stored, $token);
    }

    /**
     * Short, human-readable, unique, unguessable code used as the transfer
     * narration and the WhatsApp reference (e.g. TC-7K2M9Q).
     */
    protected function generateBankTransferReference(): string
    {
        // No 0/O/1/I so the code can be read out or copied without ambiguity.
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'TC-';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (!Payment::withTrashed()->where('gateway_reference', $code)->exists()) {
                return $code;
            }
        }

        return 'TC-' . strtoupper((string) Str::ulid());
    }

}
