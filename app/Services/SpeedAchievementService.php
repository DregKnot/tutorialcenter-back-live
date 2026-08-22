<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamQuestionInteraction;
use App\Models\StudentAchievement;

class SpeedAchievementService
{
    private const WINDOWS = [
        3 => ['speed.fast_learner', 200000],
        7 => ['speed.lightning_brain', 480000],
        10 => ['speed.speed_champion', 690000],
        15 => ['speed.speed_legend', 960000],
    ];

    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    public function evaluate(ExamAttempt $attempt): array
    {
        if ($attempt->status !== ExamAttempt::COMPLETED) {
            return [];
        }

        $attempt->loadMissing(['student', 'examYear']);
        $interactions = $attempt->questionInteractions()
            ->where('action', ExamQuestionInteraction::ANSWERED)
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get();
        $awards = [];
        $run = [];

        foreach ($interactions as $interaction) {
            if (
                ! $interaction->is_correct ||
                $interaction->response_duration_ms === null
            ) {
                $run = [];

                continue;
            }

            $run[] = $interaction;

            if ($interaction->response_duration_ms <= 60000) {
                $awards[] = $this->award(
                    $attempt,
                    'speed.quick_thinker',
                    'question:'.$interaction->past_question_id,
                    [
                        'question_id' => $interaction->past_question_id,
                        'response_duration_ms' => $interaction->response_duration_ms,
                    ]
                );
            }

            foreach (self::WINDOWS as $questionCount => [$code, $limit]) {
                if (count($run) < $questionCount) {
                    continue;
                }

                $window = array_slice($run, -$questionCount);
                $duration = collect($window)->sum('response_duration_ms');

                if ($duration <= $limit) {
                    $first = $window[0];
                    $last = end($window);
                    $awards[] = $this->award(
                        $attempt,
                        $code,
                        'window:'.$first->id.':'.$last->id,
                        [
                            'question_count' => $questionCount,
                            'start_interaction_id' => $first->id,
                            'end_interaction_id' => $last->id,
                            'total_duration_ms' => $duration,
                        ]
                    );
                }
            }
        }

        return array_values(array_filter($awards));
    }

    private function award(
        ExamAttempt $attempt,
        string $code,
        string $occurrenceKey,
        array $metadata
    ): ?StudentAchievement {
        $award = $this->awardService->award(
            $attempt->student,
            $code,
            occurrenceKey: 'attempt:'.$attempt->id.':'.$occurrenceKey,
            context: [
                'exam_attempt_id' => $attempt->id,
                'subject_id' => $attempt->examYear->subject_id,
                'metadata' => array_merge([
                    'source' => 'completed_exam_speed',
                ], $metadata),
            ]
        );

        $this->onboardingAchievementService
            ->firstQualifyingAchievementAwarded($award);

        return $award->wasRecentlyCreated ? $award : null;
    }
}
