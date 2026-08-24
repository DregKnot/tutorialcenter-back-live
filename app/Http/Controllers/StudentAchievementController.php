<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use App\Models\StudentAchievementProgress;
use App\Models\StudentWeeklyPerformance;
use Illuminate\Http\Request;

class StudentAchievementController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user();
        $now = now();

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
                    'eligible_exam_answers' => (int) ($practiceMilestone?->integer_value ?? 0),
                ],
                'streak' => [
                    'ongoing' => (int) ($streak?->integer_value ?? 0),
                    'max' => (int) ($streakMetadata['maximum_streak'] ?? 0),
                    'last_activity_date' => $streakMetadata['last_activity_date'] ?? null,
                    'started_date' => $streakMetadata['streak_started_date'] ?? null,
                    'timezone' => $streakMetadata['timezone'] ?? 'Africa/Lagos',
                ],
                'time_investment' => [
                    'active_seconds' => (int) ($timeInvestment?->duration_seconds ?? 0),
                    'active_hours' => round(
                        ((int) ($timeInvestment?->duration_seconds ?? 0)) / 3600,
                        2
                    ),
                ],
                'latest_weekly_performance' => $latestWeeklyPerformance,
                'records' => $progress,
            ],
        ]);
    }
}
