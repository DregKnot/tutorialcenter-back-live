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
                $examYear->id
            );

        $award = $this->onboardingAchievementService->firstPracticeStarted(
            $student,
            $attempt
        );

        StudentNotificationService::notify($student, 'Started Exam', ["You have started the exam: {$examYear->examBody->name} - {$examYear->subject->name}"]);

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
}
