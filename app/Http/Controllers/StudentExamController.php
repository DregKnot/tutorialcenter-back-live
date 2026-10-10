<?php

namespace App\Http\Controllers;

use App\Models\ExamYear;
use App\Services\ExamService;
use App\Services\OnboardingAchievementService;
use App\Services\StudentNotificationService;
use Illuminate\Http\Request;

class StudentExamController extends Controller
{
    protected $examService;

    public function __construct(
        ExamService $examService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {
        $this->examService = $examService;
    }

    public function available(Request $request)
    {
        $student = $request->user();

        $exams = ExamYear::with([
            'examBody',
            'subject',
        ])
            ->get()
            ->filter(function ($exam) use ($student) {

                return $student->canAccessExam(
                    $exam->id
                );
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $exams,
        ]);
    }

    public function start(
        Request $request,
        ExamYear $examYear
    ) {
        $student = $request->user();

        $attempt = $this->examService
            ->startExam(
                $student,
                $examYear->id,
                $request->input('paper_type')
            );

        try {
            $attempt->update([
                'timer' => (int) $request->input('timer', 50),
            ]);
        } catch (\Throwable $e) {
            // Column may not exist on live DB before migration runs
            \Illuminate\Support\Facades\Log::warning('Could not save timer to exam_attempts: ' . $e->getMessage());
        }

        $award = $this->onboardingAchievementService->firstPracticeStarted(
            $student,
            $attempt
        );

        if (! StudentNotificationService::enabled()) {
            StudentNotificationService::notify($student, 'Started Exam', ["You have started the exam: {$examYear->examBody->name} - {$examYear->subject->name}"]);
        }

        return response()->json([
            'success' => true,
            'attempt' => $attempt,
            'new_achievement' => $this->formatAchievement($award),
        ]);
    }

    private function formatAchievement($award): ?array
    {
        if (! $award?->wasRecentlyCreated) {
            return null;
        }

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
    }

    public function startJamb(Request $request)
    {
        $request->validate([
            'exam_year_ids' => 'required|array|size:4',
            'exam_year_ids.*' => 'required|exists:exam_years,id',
            'timer' => 'nullable|integer|min:1',
            'paper_types' => 'nullable|array',
        ]);

        $student = $request->user();
        $examYearIds = $request->input('exam_year_ids');
        $timer = (int) $request->input('timer', 120);

        $attempt = $this->examService->startJambExam($student, $examYearIds, $timer);

        $award = $this->onboardingAchievementService->firstPracticeStarted(
            $student,
            $attempt
        );

        if (! StudentNotificationService::enabled()) {
            StudentNotificationService::notify($student, 'Started JAMB Mock Exam', ["You have started a JAMB practice session."]);
        }

        return response()->json([
            'success' => true,
            'attempt' => $attempt,
            'new_achievement' => $this->formatAchievement($award),
        ]);
    }

}
