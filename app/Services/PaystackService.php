<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CoursesEnrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\SubjectsEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackService
{
    protected string $secretKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->secretKey = (string) config('services.paystack.secret_key');
        $this->baseUrl = rtrim((string) config('services.paystack.payment_url', 'https://api.paystack.co'), '/');
    }

    /**
     * Verify transaction with Paystack API server-to-server.
     */
    public function verifyTransaction(string $reference): array
    {
        if (empty(trim($this->secretKey))) {
            Log::warning('Paystack secret key is not configured in environment.', ['reference' => $reference]);
            return [
                'status' => false,
                'message' => 'Paystack secret key is not configured in backend environment.',
                'data' => null,
            ];
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(30)
                ->get("{$this->baseUrl}/transaction/verify/" . urlencode($reference));

            if (!$response->successful()) {
                Log::error('Paystack verification HTTP error', [
                    'reference' => $reference,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return [
                    'status' => false,
                    'message' => $response->json('message') ?? 'Paystack verification request failed.',
                    'data' => null,
                ];
            }

            $payload = $response->json();
            $status = $payload['status'] ?? false;
            $data = $payload['data'] ?? [];
            $gatewayStatus = $data['status'] ?? null;

            if ($status && $gatewayStatus === 'success') {
                return [
                    'status' => true,
                    'message' => 'Transaction verified successfully.',
                    'data' => $data,
                ];
            }

            return [
                'status' => false,
                'message' => $data['gateway_response'] ?? ($payload['message'] ?? 'Transaction was not successful.'),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('Paystack verification exception', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => false,
                'message' => 'Exception during Paystack verification: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Validate incoming Paystack Webhook HMAC-SHA512 signature.
     */
    public function validateWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if (empty($signature) || empty($this->secretKey)) {
            return false;
        }

        $computedSignature = hash_hmac('sha512', $rawPayload, $this->secretKey);

        return hash_equals($computedSignature, $signature);
    }

    /**
     * Core idempotent processor for successful payments (from Webhook or Atomic Verification).
     */
    public function processSuccessfulPayment(array $data, array $fallbackContext = []): array
    {
        $reference = $data['reference'] ?? ($fallbackContext['reference'] ?? null);
        if (!$reference) {
            throw new \InvalidArgumentException('Payment reference is missing.');
        }

        return DB::transaction(function () use ($data, $reference, $fallbackContext) {
            // 1. Idempotency is keyed on (reference, enrollment), because one
            //    Paystack reference may cover several courses. Looking up a
            //    single row here previously short-circuited the whole call and
            //    left every course after the first one unactivated.
            $existingPayments = Payment::where('gateway_reference', $reference)
                ->where('status', 'successful')
                ->lockForUpdate()
                ->get();

            $hadSuccessfulPayments = $existingPayments->isNotEmpty();

            $amountPaid = isset($data['amount']) ? ((float) $data['amount']) / 100 : ((float) ($fallbackContext['amount'] ?? 0));
            $paidAt = isset($data['paid_at']) ? new \DateTime($data['paid_at']) : now();
            $channel = $data['channel'] ?? ($fallbackContext['payment_method'] ?? 'card');
            $metadata = $data['metadata'] ?? ($fallbackContext['metadata'] ?? []);
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?: [];
            }

            $customerEmail = $data['customer']['email'] ?? ($fallbackContext['email'] ?? null);
            $processedPayments = [];

            // 2. Identify the payment structure from metadata
            $type = $metadata['type'] ?? ($fallbackContext['type'] ?? 'student_enrollment');

            if ($type === 'guardian_enrollment' && !empty($metadata['students'])) {
                // Guardian paying for multiple wards
                foreach ($metadata['students'] as $studentItem) {
                    $studentId = $studentItem['student_id'] ?? null;
                    if (!$studentId) {
                        Log::warning('Guardian payment entry has no student_id; skipping', [
                            'reference' => $reference,
                        ]);
                        continue;
                    }

                    $student = Student::find($studentId);
                    if (!$student) {
                        Log::warning('Guardian payment references an unknown student; skipping', [
                            'reference' => $reference,
                            'student_id' => $studentId,
                        ]);
                        continue;
                    }

                    foreach ($studentItem['courses'] ?? [] as $courseItem) {
                        $payment = $this->enrollStudentInCourse(
                            $student,
                            $courseItem,
                            $reference,
                            $channel,
                            $paidAt,
                            $data
                        );
                        if ($payment) {
                            $processedPayments[] = $payment;
                        }
                    }
                }
            } else {
                // Single student flow (standard registration, campaign, or dashboard renewal)
                $studentId = $metadata['student_id'] ?? ($fallbackContext['student_id'] ?? null);
                $student = $studentId ? Student::find($studentId) : null;

                if (!$student && $customerEmail) {
                    // Try finding student by customer email if ID wasn't in metadata
                    $student = Student::where('email', $customerEmail)
                        ->orWhere('tel', explode('@', $customerEmail)[0])
                        ->first();
                }

                if (!$student) {
                    Log::warning('Could not resolve student for Paystack payment', [
                        'reference' => $reference,
                        'metadata' => $metadata,
                        'email' => $customerEmail,
                    ]);
                    throw new \RuntimeException("Student could not be resolved for payment reference {$reference}");
                }

                $coursesList = $metadata['courses'] ?? [];
                if (empty($coursesList) && !empty($metadata['course_id'])) {
                    $coursesList = [[
                        'course_id' => $metadata['course_id'],
                        'billing_cycle' => $metadata['billing_cycle'] ?? 'monthly',
                        'price' => $metadata['price'] ?? $amountPaid,
                        'subjects' => $metadata['subjects'] ?? [],
                    ]];
                } elseif (empty($coursesList) && !empty($fallbackContext['courses'])) {
                    // The client sent the whole course list as fallback metadata
                    // (Paystack returned no metadata for the charge). Honour it
                    // instead of dropping every course and throwing.
                    $coursesList = $fallbackContext['courses'];
                } elseif (empty($coursesList) && !empty($fallbackContext['course_id'])) {
                    $coursesList = [[
                        'course_id' => $fallbackContext['course_id'],
                        'billing_cycle' => $fallbackContext['billing_cycle'] ?? 'monthly',
                        'price' => $fallbackContext['price'] ?? $amountPaid,
                        'subjects' => $fallbackContext['subjects'] ?? [],
                    ]];
                }

                foreach ($coursesList as $courseItem) {
                    $payment = $this->enrollStudentInCourse(
                        $student,
                        $courseItem,
                        $reference,
                        $channel,
                        $paidAt,
                        $data
                    );
                    if ($payment) {
                        $processedPayments[] = $payment;
                    }
                }
            }

            // 3. Process referral if present
            $referralCode = $metadata['referral_code'] ?? ($fallbackContext['referral_code'] ?? null);
            // Only on a genuinely new payment: a retried verification or webhook
            // replay must not credit the affiliate bonus twice.
            if ($referralCode && $amountPaid > 0 && !empty($processedPayments)) {
                $this->notifyAffiliateSystem($student ?? null, $referralCode, $amountPaid);
            }

            // A successful charge that records nothing is the worst outcome:
            // the caller reports success while the student stays unregistered.
            // Fail loudly so verify-paystack returns an error (and the Paystack
            // webhook returns 500 so Paystack retries) instead of silently
            // pretending the courses were activated.
            if (empty($processedPayments) && !$hadSuccessfulPayments) {
                Log::error('Paystack payment resolved no enrollments; nothing recorded', [
                    'reference' => $reference,
                    'type' => $type,
                    'email' => $customerEmail,
                    'metadata' => $metadata,
                ]);

                throw new \RuntimeException(
                    "No course or student could be processed for payment reference {$reference}."
                );
            }

            return [
                'already_processed' => $hadSuccessfulPayments && empty($processedPayments),
                'payments' => empty($processedPayments) && $hadSuccessfulPayments
                    ? $existingPayments->all()
                    : $processedPayments,
            ];
        });
    }

    /**
     * Helper to enroll student in a course, attach subjects, and record the payment row.
     */
    protected function enrollStudentInCourse(
        Student $student,
        array $courseItem,
        string $reference,
        string $channel,
        $paidAt,
        array $rawPaystackData
    ): ?Payment {
        $courseId = (int) ($courseItem['course_id'] ?? 0);
        if (!$courseId) {
            Log::warning('Paystack course entry has no course_id; skipping', [
                'reference' => $reference,
                'course_item' => $courseItem,
            ]);

            return null;
        }

        $course = Course::find($courseId);
        if (!$course) {
            Log::warning('Paystack payment references an unknown course; skipping', [
                'reference' => $reference,
                'course_id' => $courseId,
            ]);

            return null;
        }

        $billingCycle = $courseItem['billing_cycle'] ?? 'monthly';
        $months = match ($billingCycle) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            default => 1,
        };

        // Price Calculation: Minimum base price derived from official Course model
        $standardPrice = (float) ($course->price * $months);
        $suppliedPrice = (float) ($courseItem['price'] ?? $standardPrice);

        // Security check: If supplied price is suspiciously low (< 100 NGN) and standard price > 1000, enforce official price
        if ($suppliedPrice < 100 && $standardPrice > 1000) {
            Log::warning('Supplied price below threshold. Enforcing official price.', [
                'supplied' => $suppliedPrice,
                'expected' => $standardPrice,
                'course_id' => $courseId
            ]);
            $suppliedPrice = $standardPrice;
        }

                // Find existing enrollment (including soft-deleted) or create new one
        $enrollment = CoursesEnrollment::withTrashed()
            ->where('course_id', $courseId)
            ->where('student_id', $student->id)
            ->first();

        $startDate = now();
        $endDate = now()->addMonths($months);

        $existingPayment = null;

        if ($enrollment) {
            // Reuse the row when this reference was already recorded for this
            // enrollment. A successful row means the work is done; a pending row
            // is upgraded below instead of inserting a duplicate that would trip
            // the (gateway_reference, course_enrollment_id) unique index.
            $existingPayment = Payment::where('gateway_reference', $reference)
                ->where('course_enrollment_id', $enrollment->id)
                ->first();

            if ($existingPayment && $existingPayment->status === 'successful') {
                Log::info('Payment already recorded for enrollment; skipping duplicate', [
                    'reference' => $reference,
                    'enrollment_id' => $enrollment->id,
                ]);

                return null;
            }

            if ($enrollment->trashed()) {
                $enrollment->restore();
            }
            $enrollment->update([
                'start_date' => $startDate,
                'end_date' => $endDate,
                'billing_cycle' => $billingCycle,
                'cost' => $suppliedPrice,
                'status' => 'active',
            ]);
        } else {
            $enrollment = CoursesEnrollment::create([
                'course_id' => $courseId,
                'student_id' => $student->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'billing_cycle' => $billingCycle,
                'cost' => $suppliedPrice,
                'status' => 'active',
            ]);
        }

        // Attach or restore selected subjects safely
        $subjects = $courseItem['subjects'] ?? [];
        if (!empty($subjects) && is_array($subjects)) {
            foreach ($subjects as $subjectItem) {
                $subId = 0;
                if (is_numeric($subjectItem)) {
                    $subId = (int) $subjectItem;
                } elseif (is_array($subjectItem) && !empty($subjectItem['id'])) {
                    $subId = (int) $subjectItem['id'];
                } elseif (is_string($subjectItem) && trim($subjectItem) !== '') {
                    $subObj = \App\Models\Subject::where('name', trim($subjectItem))->first();
                    $subId = $subObj ? $subObj->id : 0;
                }

                if ($subId > 0 && \App\Models\Subject::where('id', $subId)->exists()) {
                    $subEnrollment = SubjectsEnrollment::withTrashed()
                        ->where('course_enrollment_id', $enrollment->id)
                        ->where('student_id', $student->id)
                        ->where('subject_id', $subId)
                        ->first();

                    if ($subEnrollment) {
                        if ($subEnrollment->trashed()) {
                            $subEnrollment->restore();
                        }
                    } else {
                        SubjectsEnrollment::create([
                            'course_enrollment_id' => $enrollment->id,
                            'student_id' => $student->id,
                            'subject_id' => $subId,
                        ]);
                    }
                }
            }
        } else {
            // If subjects array was omitted in renewal payload, inherit & restore all existing subject enrollments
            SubjectsEnrollment::withTrashed()
                ->where('course_enrollment_id', $enrollment->id)
                ->where('student_id', $student->id)
                ->restore();
        }

        $paymentPayload = [
            'amount' => $suppliedPrice,
            'payment_method' => $channel ?: 'card',
            'billing_cycle' => $billingCycle,
            'gateway' => 'paystack',
            'status' => 'successful',
            'paid_at' => $paidAt,
            'meta' => [
                'paystack' => $rawPaystackData,
                'processed_at' => now()->toIso8601String(),
            ],
        ];

        if ($existingPayment) {
            // Upgrade a pending/failed row into the confirmed payment.
            $existingPayment->update(array_merge($paymentPayload, [
                'meta' => array_merge($existingPayment->meta ?? [], $paymentPayload['meta']),
            ]));
            $payment = $existingPayment->fresh();
        } else {
            $payment = Payment::create(array_merge($paymentPayload, [
                'student_id' => $student->id,
                'course_enrollment_id' => $enrollment->id,
                'gateway_reference' => $reference,
            ]));
        }

        // The enrollment is now settled by Paystack. Close any open bank-transfer
        // attempt for the same enrollment so an admin cannot later approve both
        // and so the student's "I have paid" claim leaves the review queue.
        $this->supersedeOpenBankTransfers($enrollment, $reference);

        // Evaluate achievements
        try {
            app(ExamPreparationAchievementService::class)->evaluatePayment($payment);
        } catch (\Throwable $e) {
            Log::warning('Achievement evaluation failed', ['error' => $e->getMessage()]);
        }

        return $payment;
    }

    /**
     * Close open direct-bank-transfer rows once the enrollment is paid by
     * another method, recording why so the trail is auditable (the money may
     * still have reached the bank account and need a refund).
     */
    protected function supersedeOpenBankTransfers(CoursesEnrollment $enrollment, string $reference): void
    {
        $openTransfers = Payment::where('course_enrollment_id', $enrollment->id)
            ->where('payment_method', 'bank_transfer')
            ->where('gateway', 'bank')
            ->whereIn('status', ['pending', 'failed'])
            ->get();

        foreach ($openTransfers as $transfer) {
            $meta = $transfer->meta ?? [];

            $transfer->update([
                'status' => 'cancelled',
                'meta' => array_merge($meta, [
                    'bank_transfer' => array_merge($meta['bank_transfer'] ?? [], [
                        'review' => [
                            'action' => 'superseded',
                            'by' => 'paystack',
                            'reason' => "Enrollment settled by payment {$reference}.",
                            'at' => now()->toIso8601String(),
                        ],
                    ]),
                ]),
            ]);

            Log::info('Closed superseded bank transfer after Paystack settlement', [
                'bank_reference' => $transfer->gateway_reference,
                'paystack_reference' => $reference,
                'enrollment_id' => $enrollment->id,
                'was_claimed' => !empty(($meta['bank_transfer'] ?? [])['claimed_paid_at']),
            ]);
        }
    }

    /**
     * Submit referral earnings to affiliate system if applicable.
     */
    protected function notifyAffiliateSystem(?Student $student, string $referralCode, float $totalAmount): void
    {
        try {
            $affiliateUrl = config('services.affiliate.url', env('AFFILIATE_API_URL', 'http://tutorialcenter-affiliate.test'));
            $name = $student ? trim($student->firstname . ' ' . $student->surname) : 'Student';
            $contact = $student ? ($student->tel ?: $student->email) : '';
            $earning = $totalAmount * 0.05;

            Http::timeout(10)->post("{$affiliateUrl}/api/referrals/register", [
                'name' => $name,
                'contact' => $contact,
                'referral_code' => $referralCode,
                'amount' => $earning,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Affiliate notification failed', ['error' => $e->getMessage()]);
        }
    }
}
