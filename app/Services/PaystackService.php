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
            // 1. Check if this reference was already processed successfully
            $existingPayment = Payment::where('gateway_reference', $reference)
                ->where('status', 'successful')
                ->first();

            if ($existingPayment) {
                Log::info('Payment already processed for reference', ['reference' => $reference]);
                return [
                    'already_processed' => true,
                    'payments' => [$existingPayment],
                ];
            }

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
                    if (!$studentId) continue;

                    $student = Student::find($studentId);
                    if (!$student) continue;

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
            if ($referralCode && $amountPaid > 0) {
                $this->notifyAffiliateSystem($student ?? null, $referralCode, $amountPaid);
            }

            return [
                'already_processed' => false,
                'payments' => $processedPayments,
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
        if (!$courseId) return null;

        $course = Course::find($courseId);
        if (!$course) return null;

        $billingCycle = $courseItem['billing_cycle'] ?? 'monthly';
        $months = match ($billingCycle) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            default => 1,
        };

        $price = (float) ($courseItem['price'] ?? ($course->price * $months));

        // Find existing enrollment or create new one
        $enrollment = CoursesEnrollment::where('course_id', $courseId)
            ->where('student_id', $student->id)
            ->first();

        $startDate = now();
        $endDate = now()->addMonths($months);

        if ($enrollment) {
            $enrollment->update([
                'start_date' => $startDate,
                'end_date' => $endDate,
                'billing_cycle' => $billingCycle,
                'cost' => $price,
                'status' => 'active',
            ]);
        } else {
            $enrollment = CoursesEnrollment::create([
                'course_id' => $courseId,
                'student_id' => $student->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'billing_cycle' => $billingCycle,
                'cost' => $price,
                'status' => 'active',
            ]);
        }

        // Attach selected subjects
        $subjects = $courseItem['subjects'] ?? [];
        foreach ($subjects as $subjectId) {
            if ($subjectId) {
                SubjectsEnrollment::firstOrCreate([
                    'course_enrollment_id' => $enrollment->id,
                    'student_id' => $student->id,
                    'subject_id' => (int) $subjectId,
                ]);
            }
        }

        // Create new Payment record
        $payment = Payment::create([
            'student_id' => $student->id,
            'course_enrollment_id' => $enrollment->id,
            'amount' => $price,
            'payment_method' => $channel ?: 'card',
            'billing_cycle' => $billingCycle,
            'gateway' => 'paystack',
            'status' => 'successful',
            'gateway_reference' => $reference,
            'paid_at' => $paidAt,
            'meta' => [
                'paystack' => $rawPaystackData,
                'processed_at' => now()->toIso8601String(),
            ],
        ]);

        // Evaluate achievements
        try {
            app(ExamPreparationAchievementService::class)->evaluatePayment($payment);
        } catch (\Throwable $e) {
            Log::warning('Achievement evaluation failed', ['error' => $e->getMessage()]);
        }

        return $payment;
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
