<?php
namespace App\Services;

use App\Models\Student;
use App\Models\ExamAttempt;
use App\Notifications\AssessmentNotification;
use Illuminate\Support\Facades\DB;
use App\Notifications\StudentActivityNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;

class StudentNotificationService
{
    public static function enabled(): bool
    {
        return true;
    }

    /** Persist in-app delivery atomically with the caller's activity transaction. */
    public static function activity(?Student $student, string $type, string $occurrence, array $data = [], bool $includeStudent = true, ?AssessmentNotification $studentNotification = null): void
    {
        if (! $student || ! self::enabled()) {
            return;
        }

        DB::transaction(function () use ($student, $type, $occurrence, $data, $includeStudent, $studentNotification) {
            $recipients = collect($includeStudent ? [$student] : [])
                ->concat($student->guardians()->get())
                ->concat($student->advisors()->get())
                ->unique(fn ($recipient) => $recipient->getMorphClass().':'.$recipient->getKey());

            foreach ($recipients as $recipient) {
                $key = hash('sha256', implode('|', [$student->id, $type, $occurrence, $recipient->getMorphClass(), $recipient->getKey()]));
                $inserted = DB::table('student_activity_deliveries')->insertOrIgnore([
                    'delivery_key' => $key,
                    'student_id' => $student->id,
                    'event_type' => $type,
                    'recipient_type' => $recipient->getMorphClass(),
                    'recipient_id' => $recipient->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted) {
                    if ($studentNotification && $recipient instanceof Student) {
                        $recipient->notifyNow($studentNotification, ['database']);
                        if (in_array('mail', $studentNotification->via($recipient), true)) {
                            DB::afterCommit(function () use ($recipient, $studentNotification) {
                                try {
                                    $recipient->notifyNow($studentNotification, ['mail']);
                                } catch (\Throwable $exception) {
                                    // Publication and in-app delivery have committed; report mail failures separately.
                                    report($exception);
                                }
                            });
                        }
                    } else {
                        $recipient->notify(new StudentActivityNotification($student, $type, $data));
                    }
                }
            }
        });
    }

    public static function exam(ExamAttempt $attempt, string $type): void
    {
        if (! self::enabled()) {
            return;
        }
        $attempt->loadMissing(['student', 'examYear.subject', 'examYear.examBody']);
        self::activity($attempt->student, $type, 'exam:'.$attempt->id, [
            'exam_attempt_id' => $attempt->id,
            'exam_year_id' => $attempt->exam_year_id,
            'subject' => $attempt->examYear?->subject?->name ?? 'Exam',
            'exam_body' => $attempt->examYear?->examBody?->name,
            'score' => $attempt->score,
            'total_questions' => $attempt->total_questions,
            'started_at' => $attempt->started_at?->toISOString(),
            'occurred_at' => now()->toISOString(),
            'reason' => $type === 'exam_abandoned' ? 'attempt_exceeded_two_hours' : null,
        ]);
    }

    public static function notify(?Student $student, string $type, array $data = [])
    {
        if (!$student) {
            return;
        }

        // Prevent spam (optional)
        $key = "student-activity-{$type}-{$student->id}";

        if (Cache::has($key)) {
            return;
        }

        // Load relationships
        $student->load(['guardians', 'advisors']);

        $notification = new StudentActivityNotification($student, $type, $data);

        /*
        |--------------------------------------------------------------------------
        | 1. Notify Student
        |--------------------------------------------------------------------------
        */
        $student->notify($notification);

        /*
        |--------------------------------------------------------------------------
        | 2. Notify Guardians
        |--------------------------------------------------------------------------
        */
        if ($student->guardians->isNotEmpty()) {
            Notification::send($student->guardians, $notification);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Notify Advisors
        |--------------------------------------------------------------------------
        */
        if ($student->advisors->isNotEmpty()) {
            Notification::send($student->advisors, $notification);
        }

        // Cache to prevent spam (5 mins)
        Cache::put($key, true, now()->addMinutes(5));
    }
}