<?php

namespace App\Services;

use App\Models\ExamBody;
use App\Models\Payment;
use Illuminate\Support\Str;

class ExamPreparationAchievementService
{
    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    /**
     * Award exam-preparation badges for a successful course payment.
     *
     * Only newly-created awards are returned.
     */
    public function evaluatePayment(Payment $payment): array
    {
        if ($payment->status !== 'successful') {
            return [];
        }

        $payment->loadMissing(['student', 'enrollment']);

        if (! $payment->student || ! $payment->enrollment) {
            return [];
        }

        $examBodies = ExamBody::query()
            ->where('course_id', $payment->enrollment->course_id)
            ->where('status', 'active')
            ->get();
        $awards = [];

        foreach ($examBodies as $examBody) {
            $code = $this->achievementCode($examBody);

            if (! $code) {
                continue;
            }

            $award = $this->awardService->award(
                $payment->student,
                $code,
                context: [
                    'metadata' => [
                        'source' => 'successful_exam_subscription',
                        'payment_id' => $payment->id,
                        'course_enrollment_id' => $payment->course_enrollment_id,
                        'exam_body_id' => $examBody->id,
                        'exam_body' => $examBody->name,
                    ],
                ]
            );

            $this->onboardingAchievementService
                ->firstQualifyingAchievementAwarded($award);

            if ($award->wasRecentlyCreated) {
                $awards[] = $award;
            }
        }

        return $awards;
    }

    private function achievementCode(ExamBody $examBody): ?string
    {
        $identifier = Str::lower(
            trim($examBody->slug.' '.$examBody->name)
        );

        return match (true) {
            Str::contains($identifier, 'gce') => 'exam_preparation.gce_ready',
            Str::contains($identifier, ['jamb', 'utme']) => 'exam_preparation.jamb_ready',
            Str::contains($identifier, 'waec') => 'exam_preparation.waec_ready',
            Str::contains($identifier, 'neco') => 'exam_preparation.neco_ready',
            default => null,
        };
    }
}
