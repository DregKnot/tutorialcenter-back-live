<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Services\ExamService;
use App\Services\OnboardingAchievementService;
use App\Services\StudentNotificationService;
use Illuminate\Http\Request;

class StudentExamResultController extends Controller
{
    protected $examService;

    public function __construct(
        ExamService $examService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {
        $this->examService = $examService;
    }

    public function submit(
        ExamAttempt $attempt
    ) {
        $attempt = $this->examService
            ->finalizeAttempt(
                $attempt
            );

        if ($attempt->status === ExamAttempt::COMPLETED) {
            $this->onboardingAchievementService->firstPracticeCompleted(
                $attempt->student,
                $attempt
            );
        }

        StudentNotificationService::notify($attempt->student, 'Exam Submitted', ["You have submitted the exam: {$attempt->examYear->examBody->name} - {$attempt->examYear->subject->name}. Your score is: {$attempt->score}"]);

        return response()->json([
            'success' => true,
            'result' => $attempt,
        ]);
    }

    public function history(
        Request $request
    ) {
        return response()->json([
            'success' => true,
            'data' => $request
                ->user()
                ->examAttempts()
                ->with('examYear.subject')
                ->latest()
                ->paginate(),
        ]);
    }

    public function review(
        ExamAttempt $attempt,
        ExamService $service,
        Request $request,
        // $attempt,
    ) {
        if (
            $attempt->student_id !==
            $request->user()->id
        ) {
            abort(403);
        }

        return response()->json([
            'success' => true,
            'attempt' => [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'percentage' => $attempt->percentage,
                'correct_answers' => $attempt->correct_answers,
                'wrong_answers' => $attempt->wrong_answers,
            ],
            'questions' => $service->reviewAttempt($attempt),
        ]);
    }
}
