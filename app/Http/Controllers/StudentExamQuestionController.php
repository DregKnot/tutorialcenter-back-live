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
            \Illuminate\Support\Facades\Log::warning('Could not pluck attempt answers: ' . $e->getMessage());
        }

        $questions = $attempt
            ->examYear
            ->pastQuestions()
            ->with([
                'options:id,past_question_id,label,option_text', 'group',
            ])
            ->get();

        return response()->json([
            'success' => true,
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
