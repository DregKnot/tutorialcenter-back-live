<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\StudentAchievement;

class ExamPerformanceAchievementService
{
    private const TIERS = [
        100 => ['exam_performance.diamond', 'diamond'],
        90 => ['exam_performance.platinum', 'platinum'],
        80 => ['exam_performance.gold', 'gold'],
        70 => ['exam_performance.silver', 'silver'],
        60 => ['exam_performance.bronze', 'bronze'],
    ];

    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    public function award(ExamAttempt $attempt): ?StudentAchievement
    {
        if ($attempt->status !== ExamAttempt::COMPLETED) {
            return null;
        }

        $qualifyingTier = $this->qualifyingTier((float) $attempt->percentage);

        if (! $qualifyingTier) {
            return null;
        }

        $attempt->loadMissing(['student', 'examYear.subject']);

        if (! $attempt->examYear?->subject_id) {
            return null;
        }

        $existingAward = StudentAchievement::query()
            ->where('student_id', $attempt->student_id)
            ->where('exam_attempt_id', $attempt->id)
            ->whereHas('achievement', function ($query) {
                $query->where('category', 'exam_performance');
            })
            ->first();

        if ($existingAward) {
            return $existingAward;
        }

        [$achievementCode, $tier] = $qualifyingTier;

        $award = $this->awardService->award(
            $attempt->student,
            $achievementCode,
            occurrenceKey: 'attempt:'.$attempt->id,
            context: [
                'exam_attempt_id' => $attempt->id,
                'subject_id' => $attempt->examYear->subject_id,
                'tier' => $tier,
                'metadata' => [
                    'source' => 'completed_exam_practice',
                    'exam_year_id' => $attempt->exam_year_id,
                    'subject_name' => $attempt->examYear->subject?->name,
                    'score' => $attempt->score,
                    'total_questions' => $attempt->total_questions,
                    'correct_answers' => $attempt->correct_answers,
                    'wrong_answers' => $attempt->wrong_answers,
                    'unanswered_questions' => $attempt->unanswered,
                    'accuracy_percentage' => $attempt->percentage,
                ],
            ]
        );

        $this->onboardingAchievementService
            ->firstQualifyingAchievementAwarded($award);

        return $award;
    }

    private function qualifyingTier(float $accuracy): ?array
    {
        foreach (self::TIERS as $minimumAccuracy => $tier) {
            if ($accuracy >= $minimumAccuracy) {
                return $tier;
            }
        }

        return null;
    }
}
