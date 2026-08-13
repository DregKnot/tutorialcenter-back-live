<?php

namespace App\Http\Controllers;

use App\Models\CoursesEnrollment;
use App\Models\Payment;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // Public: Store a new payment
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'student_id' => 'required|exists:students,id',
                'course_enrollment_id' => 'required|exists:courses_enrollments,id',
                'amount' => 'required|numeric|min:0',
                'payment_method' => 'required|in:card,bank_transfer,ussd,wallet,manual',
                'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
                'gateway' => 'nullable|string',
                'status' => 'required|in:pending,successful,failed,cancelled,refunded',
                'gateway_reference' => 'nullable|string|unique:payments,gateway_reference',
                'meta' => 'nullable|array',
                'paid_at' => 'nullable|date',
            ]);

            $payment = Payment::create($validated);

            CoursesEnrollment::where('id', $validated['course_enrollment_id'])
                ->update(['status' => 'active']);

            return response()->json([
                'message' => 'Payment created successfully.',
                'payment' => $payment,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create payment.',
                'error' => $e->getMessage(),
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

    // Admin: Find payments and enrollments that may need registration recovery
    public function searchRegistrationRecovery(Request $request)
    {
        $validated = $request->validate([
            'search' => 'required|string|max:255',
        ]);

        $search = trim($validated['search']);

        $payments = Payment::with(['student', 'enrollment.course', 'enrollment.subjects.subject'])
            ->where(function ($query) use ($search) {
                $query->where('gateway_reference', $search);

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }

                $query->orWhereHas('student', function ($studentQuery) use ($search) {
                        $studentQuery->where('email', $search)
                            ->orWhere('tel', $search);

                        if (ctype_digit($search)) {
                            $studentQuery->orWhere('id', (int) $search);
                        }
                });
            })
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'message' => $payments->isEmpty()
                ? 'No matching payment was found.'
                : 'Matching payments retrieved successfully.',
            'payments' => $payments,
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

                $enrollment = CoursesEnrollment::query()
                    ->lockForUpdate()
                    ->findOrFail($lockedPayment->course_enrollment_id);

                if ((int) $lockedPayment->student_id !== (int) $enrollment->student_id) {
                    abort(422, 'The payment does not belong to the enrollment student.');
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

}
