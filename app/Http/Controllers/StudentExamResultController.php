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

        if (! StudentNotificationService::enabled()) {
            StudentNotificationService::notify($attempt->student, 'Exam Submitted', ["You have submitted the exam: {$attempt->examYear->examBody->name} - {$attempt->examYear->subject->name}. Your score is: {$attempt->score}"]);
        }

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
        $student = $request->user();

        // Calculate student practice streak across all historical attempts
        $dates = $student ? $student->examAttempts()
            ->selectRaw('DATE(created_at) as attempt_date')
            ->distinct()
            ->orderBy('attempt_date', 'desc')
            ->pluck('attempt_date')
            ->toArray() : [];

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $streak = 0;
        if (!empty($dates)) {
            $firstDate = $dates[0];
            $expected = null;
            if ($firstDate === $today) {
                $streak = 1;
                $expected = now()->subDay();
            } elseif ($firstDate === $yesterday) {
                $streak = 1;
                $expected = now()->subDays(2);
            }
            if ($expected) {
                for ($i = 1; $i < count($dates); $i++) {
                    if ($dates[$i] === $expected->toDateString()) {
                        $streak++;
                        $expected->subDay();
                    } else {
                        break;
                    }
                }
            }
        }

                $activeAttempt = $student ? $student->examAttempts()
            ->where('status', ExamAttempt::IN_PROGRESS)
            ->with(['examYear.subject', 'examYear.examBody'])
            ->latest('started_at')
            ->first() : null;

        if ($activeAttempt) {
            $allocatedMinutes = (int) ($activeAttempt->timer ?: 50);
            $expiresAt = $activeAttempt->started_at
                ? $activeAttempt->started_at->copy()->addMinutes($allocatedMinutes)
                : now()->addMinutes(50);

            if (now()->greaterThanOrEqualTo($expiresAt)) {
                $activeAttempt->update([
                    'status' => ExamAttempt::ABANDONED,
                    'submitted_at' => now(),
                ]);
                $activeAttempt = null;
            } else {
                $activeAttempt->remaining_seconds = (int) max(0, round(now()->diffInSeconds($expiresAt, false)));
                $activeAttempt->timer = $allocatedMinutes;
            }
        }

        return response()->json([
            'success' => true,
            'streak' => $streak,
            'active_attempt' => $activeAttempt,
            'data' => $student
                ? $student->examAttempts()
                    ->with(['examYear.subject', 'examYear.examBody'])
                    ->latest()
                    ->paginate($request->input('per_page', 15))
                : [],
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

    public function abandon(
        ExamAttempt $attempt,
        Request $request
    ) {
        $student = $request->user();
        if ($attempt->student_id !== $student->id) {
            abort(403, 'Unauthorized.');
        }

        if ($attempt->status === ExamAttempt::IN_PROGRESS) {
            $attempt->update([
                'status' => ExamAttempt::ABANDONED,
                'submitted_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Exam attempt marked as abandoned.',
            'attempt' => $attempt,
        ]);
    }
}
