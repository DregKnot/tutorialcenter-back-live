<?php

namespace App\Services;

use App\Models\ExamActivityHeartbeat;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptActivitySession;
use App\Models\PastQuestion;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExamActivityService
{
    private const MAX_HEARTBEAT_GAP_SECONDS = 90;

    public function startSession(
        Student $student,
        ExamAttempt $attempt,
        string $sessionToken,
        array $metadata = []
    ): ExamAttemptActivitySession {
        $this->validateAttempt($student, $attempt);

        $existing = ExamAttemptActivitySession::where(
            'session_token',
            $sessionToken
        )->first();

        if ($existing) {
            if (
                $existing->student_id !== $student->id ||
                $existing->exam_attempt_id !== $attempt->id
            ) {
                abort(409, 'The activity session token has already been used.');
            }

            return $existing;
        }

        return ExamAttemptActivitySession::create([
            'exam_attempt_id' => $attempt->id,
            'student_id' => $student->id,
            'session_token' => $sessionToken,
            'started_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    public function recordHeartbeat(
        Student $student,
        ExamAttempt $attempt,
        string $sessionToken,
        string $clientEventId,
        bool $pageVisible,
        bool $appFocused,
        ?PastQuestion $question = null,
        ?string $interactionType = null,
        ?string $occurredAt = null,
        array $metadata = []
    ): ExamActivityHeartbeat {
        $this->validateAttempt($student, $attempt);

        if ($question && $question->exam_year_id !== $attempt->exam_year_id) {
            abort(422, 'The question does not belong to this exam attempt.');
        }

        return DB::transaction(function () use (
            $student,
            $attempt,
            $sessionToken,
            $clientEventId,
            $pageVisible,
            $appFocused,
            $question,
            $interactionType,
            $occurredAt,
            $metadata
        ) {
            $existingHeartbeat = ExamActivityHeartbeat::where(
                'client_event_id',
                $clientEventId
            )->first();

            if ($existingHeartbeat) {
                if (
                    $existingHeartbeat->student_id !== $student->id ||
                    $existingHeartbeat->exam_attempt_id !== $attempt->id
                ) {
                    abort(409, 'The client event ID has already been used.');
                }

                return $existingHeartbeat;
            }

            $session = ExamAttemptActivitySession::where(
                'session_token',
                $sessionToken
            )
                ->where('exam_attempt_id', $attempt->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                abort(422, 'The activity session has not been started.');
            }

            if ($session->ended_at) {
                abort(409, 'The activity session has already ended.');
            }

            $receivedAt = now();
            $previousHeartbeat = ExamActivityHeartbeat::where(
                'session_token',
                $sessionToken
            )
                ->latest('received_at')
                ->first();

            $activeSeconds = $this->qualifyingInterval(
                $previousHeartbeat,
                $receivedAt,
                $pageVisible,
                $appFocused
            );

            $heartbeat = ExamActivityHeartbeat::create([
                'exam_attempt_id' => $attempt->id,
                'student_id' => $student->id,
                'session_token' => $sessionToken,
                'question_id' => $question?->id,
                'client_event_id' => $clientEventId,
                'page_visible' => $pageVisible,
                'app_focused' => $appFocused,
                'interaction_type' => $interactionType,
                'occurred_at' => $occurredAt
                    ? Carbon::parse($occurredAt)
                    : $receivedAt,
                'received_at' => $receivedAt,
                'metadata' => $metadata,
            ]);

            $session->update([
                'active_seconds' => $session->active_seconds + $activeSeconds,
                'last_heartbeat_at' => $receivedAt,
            ]);

            return $heartbeat;
        });
    }

    public function endSession(
        Student $student,
        ExamAttempt $attempt,
        string $sessionToken,
        string $reason = 'completed'
    ): ExamAttemptActivitySession {
        $this->validateAttempt($student, $attempt, false);

        $session = ExamAttemptActivitySession::where(
            'session_token',
            $sessionToken
        )
            ->where('exam_attempt_id', $attempt->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        if ($session->ended_at) {
            return $session;
        }

        $session->update([
            'ended_at' => now(),
            'ended_reason' => $reason,
        ]);

        return $session->refresh();
    }

    public function endOpenSessionsForAttempt(
        ExamAttempt $attempt,
        string $reason
    ): int {
        return ExamAttemptActivitySession::where(
            'exam_attempt_id',
            $attempt->id
        )
            ->whereNull('ended_at')
            ->update([
                'ended_at' => now(),
                'ended_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    private function validateAttempt(
        Student $student,
        ExamAttempt $attempt,
        bool $requireInProgress = true
    ): void {
        if ($attempt->student_id !== $student->id) {
            abort(403, 'This exam attempt does not belong to the student.');
        }

        if (
            $requireInProgress &&
            $attempt->status !== ExamAttempt::IN_PROGRESS
        ) {
            abort(409, 'The exam attempt is no longer in progress.');
        }
    }

    private function qualifyingInterval(
        ?ExamActivityHeartbeat $previousHeartbeat,
        Carbon $receivedAt,
        bool $pageVisible,
        bool $appFocused
    ): int {
        if (
            ! $previousHeartbeat ||
            ! $previousHeartbeat->page_visible ||
            ! $previousHeartbeat->app_focused ||
            ! $pageVisible ||
            ! $appFocused
        ) {
            return 0;
        }

        $gap = (int) $previousHeartbeat->received_at
            ->diffInSeconds($receivedAt);

        return $gap <= self::MAX_HEARTBEAT_GAP_SECONDS
            ? $gap
            : 0;
    }
}
