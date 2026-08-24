<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamQuestionInteraction;
use App\Models\PastQuestion;
use App\Models\PastQuestionOption;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class ExamInteractionService
{
    public function __construct(
        protected ExamService $examService
    ) {}

    public function recordViewed(
        Student $student,
        ExamAttempt $attempt,
        PastQuestion $question,
        int $sequenceNumber,
        string $clientEventId,
        array $metadata = []
    ): ExamQuestionInteraction {
        $this->validateContext($student, $attempt, $question);

        if ($existing = $this->existingEvent(
            $student,
            $attempt,
            $clientEventId
        )) {
            return $existing;
        }

        return ExamQuestionInteraction::create([
            'exam_attempt_id' => $attempt->id,
            'student_id' => $student->id,
            'past_question_id' => $question->id,
            'sequence_number' => $sequenceNumber,
            'action' => ExamQuestionInteraction::VIEWED,
            'question_shown_at' => now(),
            'client_event_id' => $clientEventId,
            'metadata' => $metadata,
        ]);
    }

    public function recordAnswered(
        Student $student,
        ExamAttempt $attempt,
        PastQuestion $question,
        PastQuestionOption $option,
        int $sequenceNumber,
        string $clientEventId,
        array $metadata = []
    ): ExamQuestionInteraction {
        $this->validateContext($student, $attempt, $question);

        if ($option->past_question_id !== $question->id) {
            abort(422, 'The selected option does not belong to this question.');
        }

        if ($existing = $this->existingEvent(
            $student,
            $attempt,
            $clientEventId
        )) {
            return $existing;
        }

        return DB::transaction(function () use (
            $student,
            $attempt,
            $question,
            $option,
            $sequenceNumber,
            $clientEventId,
            $metadata
        ) {
            $shown = $this->latestViewedInteraction($attempt, $question);
            $submittedAt = now();

            $this->examService->submitAnswer($attempt, $question, $option);

            return ExamQuestionInteraction::create([
                'exam_attempt_id' => $attempt->id,
                'student_id' => $student->id,
                'past_question_id' => $question->id,
                'past_question_option_id' => $option->id,
                'sequence_number' => $sequenceNumber,
                'action' => ExamQuestionInteraction::ANSWERED,
                'is_correct' => (bool) $option->is_correct,
                'question_shown_at' => $shown?->question_shown_at,
                'action_submitted_at' => $submittedAt,
                'response_duration_ms' => $this->responseDurationMs(
                    $shown?->question_shown_at,
                    $submittedAt
                ),
                'client_event_id' => $clientEventId,
                'metadata' => $metadata,
            ]);
        });
    }

    public function recordSkipped(
        Student $student,
        ExamAttempt $attempt,
        PastQuestion $question,
        int $sequenceNumber,
        string $clientEventId,
        array $metadata = []
    ): ExamQuestionInteraction {
        $this->validateContext($student, $attempt, $question);

        if ($existing = $this->existingEvent(
            $student,
            $attempt,
            $clientEventId
        )) {
            return $existing;
        }

        return DB::transaction(function () use (
            $student,
            $attempt,
            $question,
            $sequenceNumber,
            $clientEventId,
            $metadata
        ) {
            $shown = $this->latestViewedInteraction($attempt, $question);
            $submittedAt = now();

            ExamAttemptAnswer::where('exam_attempt_id', $attempt->id)
                ->where('past_question_id', $question->id)
                ->delete();

            return ExamQuestionInteraction::create([
                'exam_attempt_id' => $attempt->id,
                'student_id' => $student->id,
                'past_question_id' => $question->id,
                'sequence_number' => $sequenceNumber,
                'action' => ExamQuestionInteraction::SKIPPED,
                'is_correct' => false,
                'question_shown_at' => $shown?->question_shown_at,
                'action_submitted_at' => $submittedAt,
                'response_duration_ms' => $this->responseDurationMs(
                    $shown?->question_shown_at,
                    $submittedAt
                ),
                'client_event_id' => $clientEventId,
                'metadata' => $metadata,
            ]);
        });
    }

    private function validateContext(
        Student $student,
        ExamAttempt $attempt,
        PastQuestion $question
    ): void {
        if ($attempt->student_id !== $student->id) {
            abort(403, 'This exam attempt does not belong to the student.');
        }

        if ($attempt->status !== ExamAttempt::IN_PROGRESS) {
            abort(409, 'The exam attempt is no longer in progress.');
        }

        if ($question->exam_year_id !== $attempt->exam_year_id) {
            abort(422, 'The question does not belong to this exam attempt.');
        }
    }

    private function existingEvent(
        Student $student,
        ExamAttempt $attempt,
        string $clientEventId
    ): ?ExamQuestionInteraction {
        $interaction = ExamQuestionInteraction::where(
            'client_event_id',
            $clientEventId
        )->first();

        if (! $interaction) {
            return null;
        }

        if (
            $interaction->student_id !== $student->id ||
            $interaction->exam_attempt_id !== $attempt->id
        ) {
            abort(409, 'The client event ID has already been used.');
        }

        return $interaction;
    }

    private function latestViewedInteraction(
        ExamAttempt $attempt,
        PastQuestion $question
    ): ?ExamQuestionInteraction {
        return ExamQuestionInteraction::where(
            'exam_attempt_id',
            $attempt->id
        )
            ->where('past_question_id', $question->id)
            ->where('action', ExamQuestionInteraction::VIEWED)
            ->latest('question_shown_at')
            ->first();
    }

    private function responseDurationMs($shownAt, $submittedAt): ?int
    {
        if (! $shownAt) {
            return null;
        }

        return max(0, $shownAt->diffInMilliseconds($submittedAt));
    }
}
