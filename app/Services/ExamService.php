<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\PastQuestion;
use App\Models\PastQuestionOption;
use App\Models\Student;
use App\Models\StudentSubjectTrial;
use Illuminate\Support\Facades\DB;

class ExamService
{
    public function __construct(
        protected SubjectTrialService $subjectTrialService,
        protected ExamActivityService $examActivityService
    ) {}

    public function startExam(Student $student, $examYearId)
    {
        return DB::transaction(function () use (
            $student,
            $examYearId
        ) {

            if (! $student->canAccessExam($examYearId)) {
                abort(403, 'Not eligible');
            }

            /*
        |------------------------------------------
        | Ignore old dead sessions
        |------------------------------------------
        */

            $existingAttempt = ExamAttempt::lockForUpdate()
                ->where(
                    'student_id',
                    $student->id
                )
                ->where(
                    'exam_year_id',
                    $examYearId
                )
                ->where(
                    'status',
                    ExamAttempt::IN_PROGRESS
                )
                ->where(
                    'started_at',
                    '>=',
                    now()->subHours(2)
                )
                ->first();

            if ($existingAttempt) {
                $this->subjectTrialService->recordStarted($existingAttempt);

                return $existingAttempt;
            }

            $questionsCount = PastQuestion::where(
                'exam_year_id',
                $examYearId
            )->count();

            $attempt = ExamAttempt::create([
                'student_id' => $student->id,
                'exam_year_id' => $examYearId,
                'total_questions' => $questionsCount,
                'started_at' => now(),
                'status' => ExamAttempt::IN_PROGRESS,
            ]);

            $this->subjectTrialService->recordStarted($attempt);

            return $attempt;
        });
    }

    public function submitAnswer(
        ExamAttempt $attempt,
        PastQuestion $question,
        PastQuestionOption $option
    ) {
        if (
            $attempt->status !== ExamAttempt::IN_PROGRESS
        ) {
            abort(403, 'Exam already completed');
        }

        if (
            $question->exam_year_id !==
            $attempt->exam_year_id
        ) {
            abort(422, 'Invalid question');
        }

        if (
            $option->past_question_id !==
            $question->id
        ) {
            abort(422, 'Invalid option');
        }

        return ExamAttemptAnswer::updateOrCreate(
            [
                'exam_attempt_id' => $attempt->id,
                'past_question_id' => $question->id,
            ],
            [
                'past_question_option_id' => $option->id,
            ]
        );
    }

    public function finalizeAttempt(ExamAttempt $attempt)
    {
        if (
            $attempt->status !== ExamAttempt::IN_PROGRESS
        ) {
            return $attempt;
        }

        $answers = $attempt->answers()
            ->with('option:id,is_correct')
            ->get();

        $correct = $answers
            ->filter(fn ($answer) => $answer->is_correct)
            ->count();

        $wrong = $answers
            ->filter(fn ($answer) => ! $answer->is_correct)
            ->count();

        $total = $attempt->total_questions;

        $unanswered = $total - ($correct + $wrong);

        $percentage = $total > 0
            ? ($correct / $total) * 100
            : 0;

        $attempt->update([
            'correct_answers' => $correct,
            'wrong_answers' => $wrong,
            'unanswered' => $unanswered,
            'score' => $correct,
            'percentage' => round($percentage, 2),
            'submitted_at' => now(),
            'status' => ExamAttempt::COMPLETED,
        ]);

        $this->subjectTrialService->recordEnded(
            $attempt,
            StudentSubjectTrial::COMPLETED
        );

        $this->examActivityService->endOpenSessionsForAttempt(
            $attempt,
            'submitted'
        );

        return $attempt;
    }

    public function reviewAttempt(
        ExamAttempt $attempt
    ) {
        return $attempt->answers()
            ->with([
                'question.options',
                'option',
            ])
            ->get()
            ->map(function ($answer) {

                $correctOption = $answer->question
                    ->options
                    ->firstWhere(
                        'is_correct',
                        true
                    );

                return [

                    'question_id' => $answer->question->id,

                    'question_number' => $answer->question->question_number,

                    'question' => $answer->question->question,

                    'explanation' => $answer->question->explanation,

                    'is_correct' => $answer->is_correct,

                    'student_answer' => [
                        'id' => $answer->option?->id,

                        'label' => $answer->option?->label,

                        'text' => $answer->option?->option_text,
                    ],

                    'correct_answer' => [
                        'id' => $correctOption?->id,

                        'label' => $correctOption?->label,

                        'text' => $correctOption?->option_text,
                    ],

                    'options' => $answer->question
                        ->options
                        ->map(function ($option) use ($answer) {

                            return [
                                'id' => $option->id,

                                'label' => $option->label,

                                'text' => $option->option_text,

                                'is_correct' => $option->is_correct,

                                'selected' => $option->id ===
                                    $answer->past_question_option_id,
                            ];
                        }),
                ];
            });
    }
}
