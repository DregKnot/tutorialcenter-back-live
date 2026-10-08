<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use App\Models\Student;
use App\Models\StudentAchievementProgress;
use App\Models\StudentSurvey;
use App\Models\StudentWeeklyPerformance;
use App\Services\AchievementAwardService;
use Illuminate\Http\Request;

class StudentAchievementController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user();
        $now = now();

        // Retroactive survey achievement sync: if student has submitted survey, ensure badge is awarded
        if ($student instanceof Student) {
            try {
                $hasSurvey = StudentSurvey::where('student_id', $student->id)->exists();
                if ($hasSurvey) {
                    app(AchievementAwardService::class)->award($student, 'special_event.survey_pioneer', 'once', [
                        'metadata' => [
                            'source' => 'retroactive_survey_sync',
                        ],
                    ]);
                }
            } catch (\Throwable $e) {
                // Ignore sync errors
            }
        }

        $achievements = Achievement::query()
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->with(['studentAchievements' => function ($query) use ($student) {
                $query->where('student_id', $student->id)
                    ->with('subject:id,name')
                    ->latest('awarded_at');
            }])
            ->orderBy('category')
            ->orderBy('display_order')
            ->get()
            ->map(function (Achievement $achievement) {
                $awards = $achievement->studentAchievements;

                return [
                    'id' => $achievement->id,
                    'code' => $achievement->code,
                    'name' => $achievement->name,
                    'description' => $achievement->description,
                    'category' => $achievement->category,
                    'type' => $achievement->type,
                    'tier' => $achievement->tier,
                    'scope' => $achievement->scope,
                    'repeatable' => $achievement->repeatable,
                    'progressive' => $achievement->progressive,
                    'display_order' => $achievement->display_order,
                    'icon_path' => $achievement->icon_path,
                    'requirements' => $achievement->requirements,
                    'earned' => $awards->isNotEmpty(),
                    'earned_count' => $awards->count(),
                    'awards' => $awards->map(fn ($award) => [
                        'id' => $award->id,
                        'tier' => $award->tier,
                        'period_key' => $award->period_key,
                        'occurrence_key' => $award->occurrence_key,
                        'exam_attempt_id' => $award->exam_attempt_id,
                        'subject' => $award->subject,
                        'metadata' => $award->metadata,
                        'awarded_at' => $award->awarded_at,
                    ])->values(),
                ];
            })
            ->groupBy('category');

        return response()->json([
            'success' => true,
            'data' => $achievements,
        ]);
    }

    public function progress(Request $request)
    {
        $student = $request->user();
        $progress = StudentAchievementProgress::query()
            ->where('student_id', $student->id)
            ->with('subject:id,name')
            ->orderBy('progress_key')
            ->get();
        $streak = $progress->firstWhere('progress_key', 'learning_streak');
        $streakMetadata = $streak?->metadata ?? [];
        $timeInvestment = $progress->firstWhere(
            'progress_key',
            'lifetime_active_exam_seconds'
        );
        $practiceMilestone = $progress->firstWhere(
            'progress_key',
            'eligible_exam_answers'
        );
        $latestWeeklyPerformance = StudentWeeklyPerformance::query()
            ->where('student_id', $student->id)
            ->latest('week_starts_at')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'practice' => [
                    'eligible_answers' => (int) ($practiceMilestone?->current_value ?? 0),
                    'next_target' => (int) ($practiceMilestone?->target_value ?? 50),
                    'achieved' => (bool) ($practiceMilestone?->achieved ?? false),
                ],
                'streak' => [
                    'current_streak_days' => (int) ($streak?->current_value ?? 0),
                    'best_streak_days' => (int) ($streakMetadata['best_streak_days'] ?? 0),
                    'last_practice_date' => $streakMetadata['last_practice_date'] ?? null,
                ],
                'time_investment' => [
                    'active_seconds' => (int) ($timeInvestment?->current_value ?? 0),
                    'active_hours' => round(((int) ($timeInvestment?->current_value ?? 0)) / 3600, 2),
                    'target_seconds' => (int) ($timeInvestment?->target_value ?? 3600),
                ],
                'weekly_accuracy' => [
                    'accuracy_percentage' => (float) ($latestWeeklyPerformance?->accuracy_percentage ?? 0),
                    'eligible_questions' => (int) ($latestWeeklyPerformance?->eligible_questions_count ?? 0),
                    'threshold_met' => (bool) ($latestWeeklyPerformance?->accuracy_threshold_met ?? false),
                    'best_weekly_accuracy' => (float) ($latestWeeklyPerformance?->accuracy_percentage ?? 0),
                ],
            ],
        ]);
    }
}
