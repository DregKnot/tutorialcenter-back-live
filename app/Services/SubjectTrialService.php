<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\StudentSubjectTrial;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubjectTrialService
{
    public function recordStarted(
        ExamAttempt $attempt
    ): StudentSubjectTrial {
        $subjectId = $this->subjectId($attempt);

        return DB::transaction(function () use ($attempt, $subjectId) {
            $trial = StudentSubjectTrial::where(
                'student_id',
                $attempt->student_id
            )
                ->where('subject_id', $subjectId)
                ->lockForUpdate()
                ->first();

            if (! $trial) {
                return StudentSubjectTrial::create([
                    'student_id' => $attempt->student_id,
                    'subject_id' => $subjectId,
                    'exam_attempt_id' => $attempt->id,
                    'status' => StudentSubjectTrial::STARTED,
                    'started_at' => $attempt->started_at ?? now(),
                ]);
            }

            // A system-invalidated trial must not consume the student's trial.
            if (
                $trial->status === StudentSubjectTrial::INVALIDATED &&
                ! $trial->became_eligible_at
            ) {
                $trial->update([
                    'exam_attempt_id' => $attempt->id,
                    'status' => StudentSubjectTrial::STARTED,
                    'started_at' => $attempt->started_at ?? now(),
                    'completed_at' => null,
                ]);
            }

            return $trial->refresh();
        });
    }

    public function recordEnded(
        ExamAttempt $attempt,
        string $status
    ): ?StudentSubjectTrial {
        $allowedStatuses = [
            StudentSubjectTrial::COMPLETED,
            StudentSubjectTrial::ABANDONED,
            StudentSubjectTrial::INVALIDATED,
        ];

        if (! in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Invalid subject trial status.');
        }

        return DB::transaction(function () use ($attempt, $status) {
            $trial = StudentSubjectTrial::where(
                'exam_attempt_id',
                $attempt->id
            )
                ->lockForUpdate()
                ->first();

            if (! $trial || $trial->status !== StudentSubjectTrial::STARTED) {
                return $trial;
            }

            $endedAt = $attempt->submitted_at ?? now();

            $trial->update([
                'status' => $status,
                'completed_at' => $endedAt,
                'became_eligible_at' => $status === StudentSubjectTrial::INVALIDATED
                    ? null
                    : $endedAt,
            ]);

            return $trial->refresh();
        });
    }

    public function isEligibleForMilestoneCounting(
        ExamAttempt $attempt
    ): bool {
        $trial = StudentSubjectTrial::where(
            'student_id',
            $attempt->student_id
        )
            ->where('subject_id', $this->subjectId($attempt))
            ->first();

        return $trial !== null &&
            $trial->became_eligible_at !== null &&
            $trial->exam_attempt_id !== $attempt->id;
    }

    private function subjectId(ExamAttempt $attempt): int
    {
        $subjectId = $attempt->examYear()->value('subject_id');

        if (! $subjectId) {
            abort(422, 'The exam attempt does not have a valid subject.');
        }

        return (int) $subjectId;
    }
}
