<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentAchievement;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class RankAchievementService
{
    private const RANKS = [
        ['code' => 'rank.education_legend', 'months' => 12],
        ['code' => 'rank.hall_of_fame', 'months' => 11],
        ['code' => 'rank.elite_genius', 'months' => 10],
        ['code' => 'rank.grand_scholar', 'months' => 9],
        ['code' => 'rank.academic_champion', 'months' => 8],
        ['code' => 'rank.excellence_master', 'months' => 7],
        ['code' => 'rank.academic_elite', 'months' => 6],
        ['code' => 'rank.scholar', 'months' => 5],
        ['code' => 'rank.skilled_student', 'months' => 4],
        ['code' => 'rank.student', 'months' => 3],
        ['code' => 'rank.explorer', 'months' => 2],
        ['code' => 'rank.learner', 'months' => 1],
        ['code' => 'rank.beginner', 'weeks' => 2],
    ];

    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    public function evaluate(
        Student $student,
        ?CarbonInterface $asOf = null
    ): ?StudentAchievement {
        $subscriptionStartedAt = $this->subscriptionStartedAt($student);

        if (! $subscriptionStartedAt) {
            return null;
        }

        $asOf = $asOf
            ? Carbon::instance($asOf)->copy()
            : now();

        if ($subscriptionStartedAt->isAfter($asOf)) {
            return null;
        }

        $rank = $this->qualifiedRank($subscriptionStartedAt, $asOf);

        if (! $rank) {
            return null;
        }

        $award = $this->awardService->award(
            $student,
            $rank['code'],
            context: [
                'metadata' => [
                    'source' => 'subscription_duration',
                    'subscription_started_at' => $subscriptionStartedAt,
                    'evaluated_at' => $asOf,
                ],
            ]
        );

        $this->onboardingAchievementService
            ->firstQualifyingAchievementAwarded($award);

        return $award;
    }

    public function subscriptionStartedAt(Student $student): ?Carbon
    {
        $startDate = $student->courseEnrollments()
            ->whereHas('payments', function ($query) {
                $query->where('status', 'successful');
            })
            ->min('start_date');

        return $startDate ? Carbon::parse($startDate) : null;
    }

    private function qualifiedRank(
        CarbonInterface $subscriptionStartedAt,
        CarbonInterface $asOf
    ): ?array {
        foreach (self::RANKS as $rank) {
            $qualifyingDate = isset($rank['months'])
                ? $subscriptionStartedAt->copy()->addMonthsNoOverflow($rank['months'])
                : $subscriptionStartedAt->copy()->addWeeks($rank['weeks']);

            if ($asOf->greaterThanOrEqualTo($qualifyingDate)) {
                return $rank;
            }
        }

        return null;
    }
}
