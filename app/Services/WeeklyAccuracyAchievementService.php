<?php

namespace App\Services;

use App\Models\StudentAchievement;
use App\Models\StudentWeeklyPerformance;
use InvalidArgumentException;

class WeeklyAccuracyAchievementService
{
    private const TIERS = [
        100 => ['weekly_accuracy.perfect_genius', 'perfect'],
        95 => ['weekly_accuracy.diamond', 'diamond'],
        90 => ['weekly_accuracy.platinum', 'platinum'],
        80 => ['weekly_accuracy.gold', 'gold'],
        70 => ['weekly_accuracy.silver', 'silver'],
        60 => ['weekly_accuracy.bronze', 'bronze'],
    ];

    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    public function award(
        StudentWeeklyPerformance $performance
    ): ?StudentAchievement {
        if (! $performance->finalized_at) {
            throw new InvalidArgumentException(
                'Weekly performance must be finalized before awarding a medal.'
            );
        }

        if (! $performance->is_eligible) {
            return null;
        }

        $qualifyingTier = $this->qualifyingTier(
            (float) $performance->accuracy_percentage
        );

        if (! $qualifyingTier) {
            return null;
        }

        [$achievementCode, $tier] = $qualifyingTier;
        $performance->loadMissing('student');

        $existingAward = StudentAchievement::query()
            ->where('student_id', $performance->student_id)
            ->where('period_key', $performance->week_key)
            ->whereHas('achievement', function ($query) {
                $query->where('category', 'weekly_accuracy');
            })
            ->first();

        if ($existingAward) {
            return $existingAward;
        }

        $award = $this->awardService->award(
            $performance->student,
            $achievementCode,
            occurrenceKey: $performance->week_key,
            context: [
                'tier' => $tier,
                'period_key' => $performance->week_key,
                'metadata' => [
                    'source' => 'weekly_performance',
                    'weekly_performance_id' => $performance->id,
                    'week_key' => $performance->week_key,
                    'timezone' => $performance->timezone,
                    'completed_attempts' => $performance->completed_attempts,
                    'abandoned_attempts' => $performance->abandoned_attempts,
                    'total_questions' => $performance->total_questions,
                    'correct_answers' => $performance->correct_answers,
                    'accuracy_percentage' => $performance->accuracy_percentage,
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
