<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamQuestionInteraction;
use App\Models\Student;
use App\Models\StudentAchievementProgress;
use Illuminate\Support\Facades\DB;
use Throwable;

class PracticeMilestoneService
{
    private const PROGRESS_KEY = 'eligible_exam_answers';

    private const MILESTONES = [
        50 => 'practice.question_explorer',
        100 => 'practice.question_challenger',
        250 => 'practice.knowledge_hunter',
        500 => 'practice.study_warrior',
        1000 => 'practice.academic_hero',
        2500 => 'practice.practice_champion',
        5000 => 'practice.learning_machine',
        10000 => 'practice.master_scholar',
        25000 => 'practice.education_legend',
    ];

    public function __construct(
        protected SubjectTrialService $subjectTrialService,
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    public function recordInteraction(
        ExamQuestionInteraction $interaction
    ): array {
        if (
            ! $interaction->wasRecentlyCreated ||
            $interaction->action !== ExamQuestionInteraction::ANSWERED
        ) {
            return [];
        }

        $wasAlreadyCounted = ExamQuestionInteraction::where(
            'exam_attempt_id',
            $interaction->exam_attempt_id
        )
            ->where('past_question_id', $interaction->past_question_id)
            ->where('action', ExamQuestionInteraction::ANSWERED)
            ->where('id', '<', $interaction->id)
            ->exists();

        if ($wasAlreadyCounted) {
            return [];
        }

        return $this->increment($interaction->examAttempt);
    }

    public function recordLegacyAnswer(
        ExamAttemptAnswer $answer
    ): array {
        if (! $answer->wasRecentlyCreated) {
            return [];
        }

        return $this->increment($answer->attempt);
    }

    private function increment(ExamAttempt $attempt): array
    {
        if (! $this->subjectTrialService
            ->isEligibleForMilestoneCounting($attempt)) {
            return [];
        }

        try {
            [$student, $previousTotal, $currentTotal] = DB::transaction(
                function () use ($attempt) {
                    $student = Student::lockForUpdate()
                        ->findOrFail($attempt->student_id);

                    $progress = StudentAchievementProgress::where(
                        'student_id',
                        $student->id
                    )
                        ->where('progress_key', self::PROGRESS_KEY)
                        ->whereNull('subject_id')
                        ->lockForUpdate()
                        ->first();

                    if (! $progress) {
                        $progress = StudentAchievementProgress::create([
                            'student_id' => $student->id,
                            'progress_key' => self::PROGRESS_KEY,
                            'integer_value' => 0,
                            'calculated_at' => now(),
                        ]);
                    }

                    $previousTotal = $progress->integer_value;
                    $currentTotal = $previousTotal + 1;

                    $progress->update([
                        'integer_value' => $currentTotal,
                        'calculated_at' => now(),
                    ]);

                    return [$student, $previousTotal, $currentTotal];
                }
            );

            $awards = [];

            foreach (self::MILESTONES as $threshold => $code) {
                if ($previousTotal >= $threshold || $currentTotal < $threshold) {
                    continue;
                }

                $award = $this->awardService->award(
                    $student,
                    $code,
                    context: [
                        'exam_attempt_id' => $attempt->id,
                        'subject_id' => $attempt->examYear()->value('subject_id'),
                        'metadata' => [
                            'source' => 'practice_milestone',
                            'eligible_answers' => $currentTotal,
                            'threshold' => $threshold,
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
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
