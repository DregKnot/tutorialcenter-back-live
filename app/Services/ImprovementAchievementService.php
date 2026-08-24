<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentWeeklyPerformance;

class ImprovementAchievementService
{
    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    public function evaluate(Student $student): array
    {
        $weeks = StudentWeeklyPerformance::query()
            ->where('student_id', $student->id)
            ->whereNotNull('finalized_at')
            ->orderBy('week_starts_at')
            ->get();

        if ($weeks->isEmpty()) {
            return [];
        }

        $awards = [];
        $latest = $weeks->last();
        $previous = $weeks->count() > 1
            ? $weeks->get($weeks->count() - 2)
            : null;

        if ($previous) {
            $this->transitionAward(
                $student,
                $latest,
                $previous->accuracy_percentage < 40 &&
                    $latest->accuracy_percentage >= 50 &&
                    $latest->accuracy_percentage - $previous->accuracy_percentage >= 10,
                'improvement.rising_star',
                $awards
            );

            if ($weeks->count() >= 3) {
                $beforeDecline = $weeks->get($weeks->count() - 3);
                $this->transitionAward(
                    $student,
                    $latest,
                    $beforeDecline->accuracy_percentage - $previous->accuracy_percentage >= 10 &&
                        $latest->accuracy_percentage - $previous->accuracy_percentage >= 10,
                    'improvement.comeback_student',
                    $awards
                );
            }

            $this->transitionAward(
                $student,
                $latest,
                $previous->accuracy_percentage < 70 &&
                    $latest->accuracy_percentage >= 80,
                'improvement.excellence_in_progress',
                $awards
            );
        }

        if (
            $latest->accuracy_percentage < 60 &&
            $latest->active_seconds >= 72000
        ) {
            $this->award(
                $student,
                'improvement.determination_medal',
                $latest->week_key,
                ['weekly_performance_id' => $latest->id],
                $awards
            );
        }

        $bands = [50, 60, 70, 80];
        $reached = collect($bands)->filter(function ($band) use ($weeks) {
            return $weeks->contains(
                fn ($week) => $week->accuracy_percentage >= $band
            );
        })->count();
        $startedBelow50 = $weeks->first()->accuracy_percentage < 50;

        if ($startedBelow50 && $reached >= 4) {
            $this->award(
                $student,
                'improvement.growth_champion',
                'progression',
                ['bands_reached' => $reached],
                $awards
            );
        }

        return $awards;
    }

    private function transitionAward(
        Student $student,
        StudentWeeklyPerformance $week,
        bool $qualifies,
        string $code,
        array &$awards
    ): void {
        if ($qualifies) {
            $this->award($student, $code, $week->week_key, [], $awards);
        }
    }

    private function award(
        Student $student,
        string $code,
        string $occurrenceKey,
        array $metadata,
        array &$awards
    ): void {
        $award = $this->awardService->award(
            $student,
            $code,
            occurrenceKey: $occurrenceKey,
            context: [
                'period_key' => $occurrenceKey,
                'metadata' => array_merge(
                    ['source' => 'weekly_improvement'],
                    $metadata
                ),
            ]
        );

        $this->onboardingAchievementService
            ->firstQualifyingAchievementAwarded($award);

        if ($award->wasRecentlyCreated) {
            $awards[] = $award;
        }
    }
}
