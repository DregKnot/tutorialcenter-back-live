<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Models\PastQuestion;
use App\Models\PastQuestionOption;
use App\Services\ExamService;
use App\Services\LearningStreakService;
use App\Services\OnboardingAchievementService;
use App\Services\PracticeMilestoneService;
use Illuminate\Http\Request;

class StudentExamQuestionController extends Controller
{
    protected $examService;

    public function __construct(
        ExamService $examService,
        protected OnboardingAchievementService $onboardingAchievementService,
        protected PracticeMilestoneService $practiceMilestoneService,
        protected LearningStreakService $learningStreakService
    ) {
        $this->examService = $examService;
    }

            public function questions(
        ExamAttempt $attempt
    ) {
        if ($attempt->status === ExamAttempt::ABANDONED || $attempt->status === ExamAttempt::COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'This exam session has ended or been marked as abandoned and cannot be rejoined.',
            ], 403);
        }

        $allocatedMinutes = (int) ($attempt->timer ?: 50);
        $expiresAt = $attempt->started_at
            ? $attempt->started_at->copy()->addMinutes($allocatedMinutes)
            : now()->addMinutes(50);

        if (now()->greaterThanOrEqualTo($expiresAt)) {
            $attempt->update([
                'status' => ExamAttempt::ABANDONED,
                'submitted_at' => now(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'The time limit for this exam has expired and the session has ended.',
            ], 403);
        }

        $remainingSeconds = (int) max(0, round(now()->diffInSeconds($expiresAt, false)));
        $existingAnswers = [];
        try {
            $existingAnswers = $attempt->answers()
                ->pluck('past_question_option_id', 'past_question_id')
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\SupportFacades\Log::warning('Could not pluck attempt answers: ' . $e->getMessage());
        }

        $subjects = [];
        if ($attempt->is_jamb && is_array($attempt->exam_year_ids) && count($attempt->exam_year_ids) > 0) {
            $questions = PastQuestion::whereIn('exam_year_id', $attempt->exam_year_ids)
                ->with([
                    'options:id,past_question_id,label,option_text',
                    'group',
                    'examYear.subject',
                ])
                ->orderBy('question_number', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->sortBy(function ($q) use ($attempt) {
                    $idx = array_search($q->exam_year_id, $attempt->exam_year_ids);
                    return $idx === false ? 9999 : $idx;
                })
                ->values();

            $examYears = \App\Models\ExamYear::whereIn('id', $attempt->exam_year_ids)
                ->with('subject')
                ->get()
                ->keyBy('id');

            $startIndex = 0;
            foreach ($attempt->exam_year_ids as $yearId) {
                $ey = $examYears->get($yearId);
                $subCount = $questions->where('exam_year_id', $yearId)->count();
                $subjects[] = [
                    'exam_year_id' => $yearId,
                    'subject_id' => $ey?->subject_id,
                    'name' => $ey?->subject?->name ?? "Subject {$yearId}",
                    'total_questions' => $subCount,
                    'start_index' => $startIndex,
                    'end_index' => max($startIndex, $startIndex + $subCount - 1),
                ];
                $startIndex += $subCount;
            }
        } else {
            $questionsQuery = $attempt
                ->examYear
                ->pastQuestions();

            if ($attempt->paper_type) {
                $questionsQuery->where(function ($q) use ($attempt) {
                    $q->where('paper_type', $attempt->paper_type)
                      ->orWhereNull('paper_type');
                });
            }

            $questions = $questionsQuery
                ->with([
                    'options:id,past_question_id,label,option_text', 'group',
                ])
                ->orderBy('question_number', 'asc')
                ->get();
        }

        return response()->json([
            'success' => true,
            'is_jamb' => (bool) $attempt->is_jamb,
            'subjects' => $subjects,
            'subject_scores' => $attempt->subject_scores,
            'jamb_score' => $attempt->jamb_score,
            'attempt' => $attempt->loadMissing(['examYear.subject', 'examYear.examBody']),
            'questions' => $questions,
            'answers' => $existingAnswers,
            'remaining_seconds' => $remainingSeconds,
            'timer' => $allocatedMinutes,
        ]);
    }

    public function submitAnswer(
        Request $request,
        ExamAttempt $attempt
    ) {
        $request->validate([
            'question_id' => 'required|exists:past_questions,id',
            'option_id' => 'required|exists:past_question_options,id',
        ]);

        $question = PastQuestion::findOrFail(
            $request->question_id
        );

        $option = PastQuestionOption::findOrFail(
            $request->option_id
        );

        $answer = $this->examService
            ->submitAnswer(
                $attempt,
                $question,
                $option
            );

        $this->onboardingAchievementService->firstAnswerSubmitted(
            $request->user(),
            $attempt
        );

        $this->practiceMilestoneService->recordLegacyAnswer($answer);
        $this->learningStreakService->recordActivity($request->user());

        return response()->json([
            'success' => true,
            'data' => $answer,
        ]);
    }
}
