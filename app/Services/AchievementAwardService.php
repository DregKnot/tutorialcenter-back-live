<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\ExamAttempt;
use App\Models\Student;
use App\Models\StudentAchievement;
use App\Models\Subject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AchievementAwardService
{
    public function award(
        Student $student,
        string $achievementCode,
        ?string $occurrenceKey = null,
        array $context = []
    ): StudentAchievement {
        $achievement = $this->activeAchievement($achievementCode);
        $occurrenceKey = $achievement->repeatable
            ? $this->requiredOccurrenceKey($occurrenceKey)
            : 'once';

        $this->validateContext($student, $context);

        $identity = [
            'student_id' => $student->id,
            'achievement_id' => $achievement->id,
            'occurrence_key' => $occurrenceKey,
        ];

        try {
            return DB::transaction(function () use (
                $identity,
                $achievement,
                $context
            ) {
                return StudentAchievement::firstOrCreate(
                    $identity,
                    [
                        'exam_attempt_id' => $context['exam_attempt_id'] ?? null,
                        'subject_id' => $context['subject_id'] ?? null,
                        'tier' => $context['tier'] ?? $achievement->tier,
                        'period_key' => $context['period_key'] ?? null,
                        'metadata' => $this->awardMetadata(
                            $achievement,
                            $context['metadata'] ?? []
                        ),
                        'awarded_at' => $context['awarded_at'] ?? now(),
                    ]
                );
            });
        } catch (UniqueConstraintViolationException) {
            return StudentAchievement::where($identity)->firstOrFail();
        }
    }

    public function hasAward(
        Student $student,
        string $achievementCode,
        ?string $occurrenceKey = null
    ): bool {
        return StudentAchievement::where('student_id', $student->id)
            ->whereHas('achievement', function ($query) use ($achievementCode) {
                $query->where('code', $achievementCode);
            })
            ->when(
                $occurrenceKey !== null,
                fn ($query) => $query->where(
                    'occurrence_key',
                    $occurrenceKey
                )
            )
            ->exists();
    }

    private function activeAchievement(string $code): Achievement
    {
        $now = now();

        return Achievement::where('code', $code)
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->firstOrFail();
    }

    private function requiredOccurrenceKey(?string $occurrenceKey): string
    {
        if (! $occurrenceKey) {
            throw new InvalidArgumentException(
                'Repeatable achievements require an occurrence key.'
            );
        }

        if (mb_strlen($occurrenceKey) > 255) {
            throw new InvalidArgumentException(
                'The occurrence key may not exceed 255 characters.'
            );
        }

        return $occurrenceKey;
    }

    private function validateContext(Student $student, array $context): void
    {
        if (isset($context['exam_attempt_id'])) {
            $attemptBelongsToStudent = ExamAttempt::whereKey(
                $context['exam_attempt_id']
            )
                ->where('student_id', $student->id)
                ->exists();

            if (! $attemptBelongsToStudent) {
                abort(422, 'The exam attempt is not valid for this student.');
            }
        }

        if (
            isset($context['subject_id']) &&
            ! Subject::whereKey($context['subject_id'])->exists()
        ) {
            abort(422, 'The subject is not valid.');
        }
    }

    private function awardMetadata(
        Achievement $achievement,
        array $metadata
    ): array {
        return array_merge(
            [
                'achievement_snapshot' => [
                    'code' => $achievement->code,
                    'name' => $achievement->name,
                    'category' => $achievement->category,
                    'type' => $achievement->type,
                    'tier' => $achievement->tier,
                    'requirements' => $achievement->requirements,
                ],
            ],
            $metadata
        );
    }
}
