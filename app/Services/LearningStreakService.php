<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentAchievementProgress;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

class LearningStreakService
{
    private const PROGRESS_KEY = 'learning_streak';

    /**
     * Record one answered exam-practice question for a local calendar day.
     * Repeated activity on the same day is intentionally idempotent.
     */
    public function recordActivity(
        Student $student,
        ?CarbonInterface $occurredAt = null,
        string $timezone = 'Africa/Lagos'
    ): array {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            abort(422, 'The supplied timezone is invalid.');
        }

        $activityDate = ($occurredAt ?? now())
            ->copy()
            ->setTimezone($timezone)
            ->startOfDay();

        return DB::transaction(function () use (
            $student,
            $activityDate,
            $timezone
        ) {
            $progress = StudentAchievementProgress::where('student_id', $student->id)
                ->where('progress_key', self::PROGRESS_KEY)
                ->whereNull('subject_id')
                ->lockForUpdate()
                ->first();

            if (! $progress) {
                $progress = StudentAchievementProgress::create([
                    'student_id' => $student->id,
                    'progress_key' => self::PROGRESS_KEY,
                    'integer_value' => 0,
                    'metadata' => [],
                ]);
            }

            $metadata = $progress->metadata ?? [];
            $lastActivityDate = $metadata['last_activity_date'] ?? null;

            if ($lastActivityDate === $activityDate->toDateString()) {
                return [
                    'progress' => $progress,
                ];
            }

            $previousDate = $lastActivityDate
                ? Carbon::parse($lastActivityDate, $timezone)->startOfDay()
                : null;
            $isConsecutive = $previousDate &&
                (int) $previousDate->diffInDays($activityDate) === 1;
            $currentStreak = $isConsecutive
                ? ((int) $progress->integer_value + 1)
                : 1;
            $streakStartedDate = $isConsecutive
                ? ($metadata['streak_started_date'] ?? $activityDate->toDateString())
                : $activityDate->toDateString();
            $maximumStreak = max(
                (int) ($metadata['maximum_streak'] ?? 0),
                $currentStreak
            );

            $progress->update([
                'integer_value' => $currentStreak,
                'metadata' => array_merge($metadata, [
                    'current_streak' => $currentStreak,
                    'maximum_streak' => $maximumStreak,
                    'last_activity_date' => $activityDate->toDateString(),
                    'streak_started_date' => $streakStartedDate,
                    'timezone' => $timezone,
                ]),
                'calculated_at' => now(),
            ]);

            return [
                'progress' => $progress->refresh(),
            ];
        });
    }
}
