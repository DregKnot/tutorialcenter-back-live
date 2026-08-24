<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamQuestionInteraction;
use App\Models\Student;
use App\Models\StudentWeeklyPerformance;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WeeklyPerformanceService
{
    private const REQUIRED_COMPLETED_ATTEMPTS = 6;

    public function calculate(
        Student $student,
        ?CarbonInterface $date = null,
        string $timezone = 'Africa/Lagos',
        bool $finalize = false
    ): StudentWeeklyPerformance {
        $this->validateTimezone($timezone);

        $localDate = ($date ?? now())->copy()->setTimezone($timezone);
        $weekStart = $localDate->copy()->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $localDate->copy()->endOfWeek(CarbonInterface::SUNDAY);
        $queryStart = $weekStart->copy()->utc();
        $queryEnd = $weekEnd->copy()->utc();
        $weekKey = $localDate->format('o-\WW');

        $attempts = ExamAttempt::query()
            ->where('student_id', $student->id)
            ->whereIn('status', [
                ExamAttempt::COMPLETED,
                ExamAttempt::ABANDONED,
            ])
            ->whereBetween(
                DB::raw('COALESCE(submitted_at, updated_at)'),
                [$queryStart, $queryEnd]
            )
            ->with([
                'answers.option:id,is_correct',
                'questionInteractions' => function ($query) {
                    $query->whereIn('action', [
                        ExamQuestionInteraction::ANSWERED,
                        ExamQuestionInteraction::SKIPPED,
                        ExamQuestionInteraction::TIMED_OUT,
                    ]);
                },
                'activitySessions:id,exam_attempt_id,active_seconds',
            ])
            ->get();

        $completedAttempts = $attempts
            ->where('status', ExamAttempt::COMPLETED)
            ->count();
        $abandonedAttempts = $attempts
            ->where('status', ExamAttempt::ABANDONED)
            ->count();

        $totals = [
            'total_questions' => 0,
            'correct_answers' => 0,
            'wrong_answers' => 0,
            'skipped_questions' => 0,
            'unanswered_questions' => 0,
            'active_seconds' => 0,
        ];

        foreach ($attempts as $attempt) {
            $answers = $attempt->answers
                ->sortByDesc('id')
                ->unique('past_question_id');

            $correct = $answers
                ->filter(fn ($answer) => $answer->is_correct)
                ->count();
            $wrong = $answers->count() - $correct;

            $latestQuestionActions = $attempt->questionInteractions
                ->sortByDesc('id')
                ->unique('past_question_id');
            $skipped = $latestQuestionActions
                ->whereIn('action', [
                    ExamQuestionInteraction::SKIPPED,
                    ExamQuestionInteraction::TIMED_OUT,
                ])
                ->count();
            $unanswered = max(
                0,
                $attempt->total_questions - $answers->count() - $skipped
            );

            $totals['total_questions'] += $attempt->total_questions;
            $totals['correct_answers'] += $correct;
            $totals['wrong_answers'] += $wrong;
            $totals['skipped_questions'] += $skipped;
            $totals['unanswered_questions'] += $unanswered;
            $totals['active_seconds'] += $attempt->activitySessions
                ->sum('active_seconds');
        }

        $accuracy = $totals['total_questions'] > 0
            ? round(
                ($totals['correct_answers'] / $totals['total_questions']) * 100,
                4
            )
            : 0;
        $existingFinalizedAt = StudentWeeklyPerformance::where(
            'student_id',
            $student->id
        )
            ->where('week_key', $weekKey)
            ->value('finalized_at');

        return DB::transaction(function () use (
            $student,
            $weekKey,
            $weekStart,
            $weekEnd,
            $timezone,
            $completedAttempts,
            $abandonedAttempts,
            $totals,
            $accuracy,
            $finalize,
            $existingFinalizedAt,
            $attempts
        ) {
            return StudentWeeklyPerformance::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'week_key' => $weekKey,
                ],
                [
                    'week_starts_at' => $weekStart->copy()->utc(),
                    'week_ends_at' => $weekEnd->copy()->utc(),
                    'timezone' => $timezone,
                    'completed_attempts' => $completedAttempts,
                    'abandoned_attempts' => $abandonedAttempts,
                    ...$totals,
                    'accuracy_percentage' => $accuracy,
                    'is_eligible' => $completedAttempts >= self::REQUIRED_COMPLETED_ATTEMPTS,
                    'finalized_at' => $finalize
                        ? now()
                        : $existingFinalizedAt,
                    'metadata' => [
                        'required_completed_attempts' => self::REQUIRED_COMPLETED_ATTEMPTS,
                        'accuracy_denominator' => 'all_questions',
                        'attempt_ids' => $attempts->pluck('id')->values()->all(),
                    ],
                ]
            );
        });
    }

    private function validateTimezone(string $timezone): void
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('The supplied timezone is invalid.');
        }
    }
}
