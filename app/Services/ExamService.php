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

    public function startExam(Student $student, $examYearId, ?string $paperType = null)
    {
        return DB::transaction(function () use (
            $student,
            $examYearId,
            $paperType
        ) {
            if (! $student->canAccessExam($examYearId)) {
                abort(403, 'Not eligible');
            }

            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $examYear = \App\Models\ExamYear::findOrFail($examYearId);

            // Resolve paper_type if exam year has paper types
            $resolvedPaperType = null;
            if ($examYear->has_paper_types) {
                if ($paperType && is_array($examYear->paper_types) && in_array($paperType, $examYear->paper_types)) {
                    $resolvedPaperType = $paperType;
                } elseif (!empty($examYear->paper_types) && is_array($examYear->paper_types)) {
                    $resolvedPaperType = $examYear->paper_types[0];
                } else {
                    $resolvedPaperType = $paperType;
                }
            }

            $existingAttemptQuery = ExamAttempt::lockForUpdate()
                ->where('student_id', $student->id)
                ->where('exam_year_id', $examYearId)
                ->where('status', ExamAttempt::IN_PROGRESS)
                ->where('started_at', '>=', now()->subHours(2));

            if ($resolvedPaperType) {
                $existingAttemptQuery->where('paper_type', $resolvedPaperType);
            }

            $existingAttempt = $existingAttemptQuery->first();

            if ($existingAttempt) {
                $this->subjectTrialService->recordStarted($existingAttempt);

                return $existingAttempt;
            }

            $questionsQuery = PastQuestion::where('exam_year_id', $examYearId);
            if ($resolvedPaperType) {
                $questionsQuery->where(function ($q) use ($resolvedPaperType) {
                    $q->where('paper_type', $resolvedPaperType)
                      ->orWhereNull('paper_type');
                });
            }
            $questionsCount = $questionsQuery->count();

            $attempt = ExamAttempt::create([
                'student_id' => $student->id,
                'exam_year_id' => $examYearId,
                'paper_type' => $resolvedPaperType,
                'is_jamb' => false,
                'total_questions' => $questionsCount,
                'started_at' => now(),
                'status' => ExamAttempt::IN_PROGRESS,
            ]);

            $this->subjectTrialService->recordStarted($attempt);

            StudentNotificationService::exam($attempt, 'exam_started');

            return $attempt;
        });
    }

    public function startJambExam(Student $student, array $examYearIds, int $timer = 120)
    {
        return DB::transaction(function () use (
            $student,
            $examYearIds,
            $timer
        ) {
            foreach ($examYearIds as $yearId) {
                if (! $student->canAccessExam($yearId)) {
                    abort(403, "Not eligible for exam year {$yearId}");
                }
            }

            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $existingAttempt = ExamAttempt::lockForUpdate()
                ->where('student_id', $student->id)
                ->where('is_jamb', true)
                ->where('status', ExamAttempt::IN_PROGRESS)
                ->where('started_at', '>=', now()->subHours(3))
                ->first();

            if ($existingAttempt) {
                $this->subjectTrialService->recordStarted($existingAttempt);

                return $existingAttempt;
            }

            $questionsCount = PastQuestion::whereIn('exam_year_id', $examYearIds)->count();

            // Anchor foreign key exam_year_id to first subject (Slot 1: Use of English)
            $primaryExamYearId = $examYearIds[0];

            $attempt = ExamAttempt::create([
                'student_id' => $student->id,
                'exam_year_id' => $primaryExamYearId,
                'is_jamb' => true,
                'exam_year_ids' => array_values($examYearIds),
                'total_questions' => $questionsCount,
                'timer' => $timer,
                'started_at' => now(),
                'status' => ExamAttempt::IN_PROGRESS,
            ]);

            $this->subjectTrialService->recordStarted($attempt);

            StudentNotificationService::exam($attempt, 'exam_started');

            return $attempt;
        });
    }

    public function submitAnswer(
        ExamAttempt $attempt,
        PastQuestion $question,
        PastQuestionOption $option
    ) {
        if ($attempt->status !== ExamAttempt::IN_PROGRESS) {
            abort(403, 'Exam already completed');
        }

        $validExamYearIds = ($attempt->is_jamb && is_array($attempt->exam_year_ids))
            ? $attempt->exam_year_ids
            : [$attempt->exam_year_id];

        if (! in_array($question->exam_year_id, $validExamYearIds)) {
            abort(422, 'Invalid question for this exam session');
        }

        if ($option->past_question_id !== $question->id) {
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
        return DB::transaction(function () use ($attempt) {
            $attempt = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($attempt->status !== ExamAttempt::IN_PROGRESS) {
                return $attempt;
            }

            $answers = $attempt->answers()
                ->with('option:id,is_correct')
                ->get();

            if ($attempt->is_jamb && is_array($attempt->exam_year_ids) && count($attempt->exam_year_ids) > 0) {
                $questions = PastQuestion::whereIn('exam_year_id', $attempt->exam_year_ids)
                    ->with('examYear.subject')
                    ->get();

                $answersMap = $answers->keyBy('past_question_id');
                $grouped = $questions->groupBy('exam_year_id');

                $subjectScores = [];
                $totalJambScore = 0;
                $totalCorrect = 0;
                $totalWrong = 0;

                foreach ($attempt->exam_year_ids as $yearId) {
                    $subQuestions = $grouped->get($yearId, collect());
                    $firstQ = $subQuestions->first();
                    $subjectName = $firstQ?->examYear?->subject?->name ?? "Subject {$yearId}";
                    $subjectId = $firstQ?->examYear?->subject_id;
                    $subTotal = $subQuestions->count();

                    $subCorrect = 0;
                    $subWrong = 0;

                    foreach ($subQuestions as $q) {
                        $ans = $answersMap->get($q->id);
                        if ($ans) {
                            if ($ans->is_correct) {
                                $subCorrect++;
                            } else {
                                $subWrong++;
                            }
                        }
                    }

                    // Proportional scaling to 100 max
                    // For 40-question elective: (correct / 40) * 100 = correct * 2.5
                    // For 60-question English: (correct / 60) * 100
                    $scaledScore = $subTotal > 0 ? (int) round(($subCorrect / $subTotal) * 100) : 0;
                    $scaledScore = min(100, max(0, $scaledScore));

                    $subjectScores[$subjectName] = [
                        'exam_year_id' => $yearId,
                        'subject_id' => $subjectId,
                        'subject_name' => $subjectName,
                        'correct' => $subCorrect,
                        'wrong' => $subWrong,
                        'total_questions' => $subTotal,
                        'score' => $scaledScore,
                        'max_score' => 100,
                    ];

                    $totalJambScore += $scaledScore;
                    $totalCorrect += $subCorrect;
                    $totalWrong += $subWrong;
                }

                $totalQuestions = $attempt->total_questions > 0 ? $attempt->total_questions : $questions->count();
                $unanswered = max(0, $totalQuestions - ($totalCorrect + $totalWrong));
                $compositePercentage = round(($totalJambScore / 400) * 100, 2);

                $attempt->update([
                    'correct_answers' => $totalCorrect,
                    'wrong_answers' => $totalWrong,
                    'unanswered' => $unanswered,
                    'score' => $totalJambScore,
                    'jamb_score' => $totalJambScore,
                    'percentage' => $compositePercentage,
                    'subject_scores' => $subjectScores,
                    'submitted_at' => now(),
                    'status' => ExamAttempt::COMPLETED,
                ]);

            } else {
                // Standard Single-Subject Exam Finalization
                $correct = $answers
                    ->filter(fn ($answer) => $answer->is_correct)
                    ->count();

                $wrong = $answers
                    ->filter(fn ($answer) => ! $answer->is_correct)
                    ->count();

                $total = $attempt->total_questions;
                $unanswered = max(0, $total - ($correct + $wrong));
                $percentage = $total > 0 ? ($correct / $total) * 100 : 0;

                $attempt->update([
                    'correct_answers' => $correct,
                    'wrong_answers' => $wrong,
                    'unanswered' => $unanswered,
                    'score' => $correct,
                    'percentage' => round($percentage, 2),
                    'submitted_at' => now(),
                    'status' => ExamAttempt::COMPLETED,
                ]);
            }

            $this->subjectTrialService->recordEnded(
                $attempt,
                StudentSubjectTrial::COMPLETED
            );

            $this->examActivityService->endOpenSessionsForAttempt(
                $attempt,
                'submitted'
            );

            StudentNotificationService::exam($attempt, 'exam_completed');

            return $attempt;
        });
    }

    public function reviewAttempt(ExamAttempt $attempt)
    {
        return $attempt->answers()
            ->with([
                'question.options',
                'question.examYear.subject',
                'option',
            ])
            ->get()
            ->map(function ($answer) {
                $correctOption = $answer->question
                    ->options
                    ->firstWhere('is_correct', true);

                return [
                    'question_id' => $answer->question->id,
                    'question_number' => $answer->question->question_number,
                    'exam_year_id' => $answer->question->exam_year_id,
                    'subject_name' => $answer->question->examYear?->subject?->name ?? 'Subject',
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
                                'selected' => $option->id === $answer->past_question_option_id,
                            ];
                        }),
                ];
            });
    }
}
