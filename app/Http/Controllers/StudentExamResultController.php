<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Services\ExamPerformanceAchievementService;
use App\Services\ExamService;
use App\Services\OnboardingAchievementService;
use App\Services\SpeedAchievementService;
use App\Services\StudentNotificationService;
use App\Services\TimeInvestmentAchievementService;
use Illuminate\Http\Request;

class StudentExamResultController extends Controller
{
    protected $examService;

    public function __construct(
        ExamService $examService,
        protected OnboardingAchievementService $onboardingAchievementService,
        protected ExamPerformanceAchievementService $examPerformanceAchievementService,
        protected TimeInvestmentAchievementService $timeInvestmentAchievementService,
        protected SpeedAchievementService $speedAchievementService
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

        $newAchievements = [];

        if ($attempt->status === ExamAttempt::COMPLETED) {
            $performanceAward = $this->examPerformanceAchievementService->award($attempt);
            $newAchievements = array_merge(
                $newAchievements,
                $performanceAward?->wasRecentlyCreated ? [$performanceAward] : [],
                $this->speedAchievementService->evaluate($attempt)
            );

            $completionAward = $this->onboardingAchievementService->firstPracticeCompleted(
                $attempt->student,
                $attempt
            );
            $newAchievements[] = $completionAward;
        }

        $timeResult = $this->timeInvestmentAchievementService->evaluate($attempt->student);
        $newAchievements = array_merge($newAchievements, $timeResult['awards']);

        StudentNotificationService::notify($attempt->student, 'Exam Submitted', ["You have submitted the exam: {$attempt->examYear->examBody->name} - {$attempt->examYear->subject->name}. Your score is: {$attempt->score}"]);

        return response()->json([
            'success' => true,
            'result' => $attempt,
            'new_achievements' => $this->formatAchievements($newAchievements),
        ]);
    }

    private function formatAchievements(array $awards): array
    {
        return collect($awards)
            ->filter(fn ($award) => $award?->wasRecentlyCreated)
            ->map(function ($award) {
                $award->loadMissing('achievement');

                return [
                    'id' => $award->id,
                    'code' => $award->achievement?->code,
                    'name' => $award->achievement?->name,
                    'category' => $award->achievement?->category,
                    'type' => $award->achievement?->type,
                    'tier' => $award->tier,
                    'awarded_at' => $award->awarded_at,
                ];
            })->values()->all();
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
