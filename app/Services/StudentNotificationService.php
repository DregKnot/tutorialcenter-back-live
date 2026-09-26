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
    /** Student-only delivery: database state commits before direct email delivery. */
    public static function learning(Student $student, string $type, string $occurrence, \Illuminate\Notifications\Notification $notification): void
    {
        DB::transaction(function () use ($student, $type, $occurrence, $notification) {
            if (! self::claimLearning($student, $type, $occurrence)) {
                return;
            }
            $student->notifyNow($notification, ['database']);
            if (in_array('mail', $notification->via($student), true)) {
                DB::afterCommit(function () use ($student, $notification) {
                    try {
                        $recipient = clone $student;
                        $recipient->email = trim((string) $recipient->email);
                        $recipient->notifyNow($notification, ['mail']);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }
        });
    }

    private static function claimLearning(Student $student, string $type, string $occurrence): bool
    {
        return (bool) DB::table('student_activity_deliveries')->insertOrIgnore([
            'delivery_key' => hash('sha256', 'learning|'.$student->id.'|'.$type.'|'.$occurrence),
            'student_id' => $student->id, 'event_type' => $type,
            'recipient_type' => $student->getMorphClass(), 'recipient_id' => $student->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function enrolledStudents(int $subjectId): \Illuminate\Database\Eloquent\Builder
    {
        return Student::whereHas('subjectEnrollments', function ($query) use ($subjectId) {
            $query->where('subject_id', $subjectId)->whereHas('enrollment', function ($enrollment) {
                $enrollment->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', now()));
            });
        });
    }

    /** The first after-commit callback sees all new sessions in a creation transaction. */
    public static function sessionsCreated(int $sessionId): void
    {
        $processed = app('student.session_announcements');
        if ($processed->offsetExists($sessionId)) {
            return;
        }
        $session = \App\Models\ClassSession::with('class.subject')->find($sessionId);
        if (! $session || ! $session->class || $session->class->status !== 'active') {
            return;
        }
        $sessions = $session->class->sessions()->where('id', '>=', $session->id)
            ->where('created_at', '>=', $session->created_at)
            ->where('status', 'scheduled')->whereDate('session_date', '>=', today())
            ->orderBy('session_date')->orderBy('starts_at')->get()
            ->filter(fn ($item) => self::sessionTime($item, 'starts_at')?->isFuture());
        if ($sessions->isEmpty()) {
            return;
        }
        self::enrolledStudents($session->class->subject_id)->chunkById(100, function ($students) use ($sessions, $session) {
            foreach ($students as $student) {
                try {
                    DB::transaction(function () use ($student, $sessions, $session) {
                        $new = $sessions->filter(fn ($item) => self::claimLearning($student, 'class_session_announced', (string) $item->id));
                        if ($new->isEmpty()) {
                            return;
                        }
                        $ids = $new->pluck('id')->values()->all();
                        $subject = $session->class->subject?->name ?? $session->class->title;
                        $data = [
                            'class_id' => $session->class_id,
                            'subject_id' => $session->class->subject_id,
                            'subject_name' => $subject,
                            'class_title' => $session->class->title,
                            'session_ids' => $ids,
                            'session_count' => count($ids),
                            'first_session_at' => self::sessionTime($new->first(), 'starts_at')->toISOString(),
                            'last_session_at' => self::sessionTime($new->last(), 'starts_at')->toISOString(),
                            // Use the actual announced meetings, including holiday/date exclusions.
                            'schedule_times' => $new->map(function ($item) {
                                $start = self::sessionTime($item, 'starts_at');
                                $end = self::sessionTime($item, 'ends_at');
                                return $start->format('l').': '.$start->format('g:i A')
                                    .($end ? '–'.$end->format('g:i A') : '');
                            })->unique()->values()->all(),
                        ];
                        $message = count($ids) === 1
                            ? 'A new live class for '.$subject.' has been scheduled.'
                            : count($ids).' live classes for '.$subject.' have been scheduled on separate dates.';
                        self::learning($student, 'class_sessions_created', implode(',', $ids),
                            new \App\Notifications\StudentLearningNotification('class_sessions_created', $message, $data));
                    });
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });
        foreach ($sessions as $announcedSession) {
            $processed[$announcedSession->id] = true;
        }
    }

    public static function sessionTime(\App\Models\ClassSession $session, string $field): ?\Carbon\Carbon
    {
        if (! $session->session_date || ! $session->{$field}) {
            return null;
        }
        $time = \Carbon\Carbon::parse($session->session_date->toDateString().' '.$session->{$field});
        if ($field === 'ends_at' && $session->starts_at && $session->ends_at < $session->starts_at) {
            $time->addDay();
        }
        return $time;
    }

    public static function recordingAvailable(int $sessionId): void
    {
        $session = \App\Models\ClassSession::with('class.subject')->find($sessionId);
        if (! $session || ! $session->class || ! filter_var($session->recording_link, FILTER_VALIDATE_URL)
            || ! self::sessionTime($session, 'ends_at')?->isPast()) {
            return;
        }
        self::enrolledStudents($session->class->subject_id)->chunkById(100, function ($students) use ($session) {
            foreach ($students as $student) {
                try {
                    self::learning($student, 'class_recording_available', (string) $session->id,
                        new \App\Notifications\StudentLearningNotification('class_recording_available',
                            'The recording for '.$session->class->title.' on '.$session->session_date->format('j M Y').' is available.',
                            ['class_session_id' => $session->id, 'class_id' => $session->class_id, 'recording_link' => $session->recording_link]));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    public static function subscriptionReminder(int $enrollmentId): void
    {
        DB::transaction(function () use ($enrollmentId) {
            $enrollment = \App\Models\CoursesEnrollment::with(['student.guardians', 'course'])->lockForUpdate()->find($enrollmentId);
            if (! $enrollment || ! $enrollment->student || ! in_array($enrollment->status, ['active', 'expired'], true)
                || ! $enrollment->end_date || $enrollment->start_date?->isFuture()) {
                return;
            }

            $tz = config('app.timezone', 'Africa/Lagos');
            $today = now()->timezone($tz)->startOfDay();
            $expiryDay = $enrollment->end_date->copy()->timezone($tz)->startOfDay();
            $diffDays = (int) $today->diffInDays($expiryDay, false);

            // Determine if today is an eligible milestone
            // Pre-expiry: 7 (a week before), 3 (3 days before), 0 (the day it expires)
            // Post-expiry: every 3 days after it expires for 1 month (-3, -6, -9, -12, -15, -18, -21, -24, -27, -30)
            $isEligibleMilestone = false;
            if ($diffDays === 7 || $diffDays === 3 || $diffDays === 0) {
                $isEligibleMilestone = true;
            } elseif ($diffDays < 0) {
                $daysAfter = abs($diffDays);
                if ($daysAfter >= 1 && $daysAfter <= 30 && ($daysAfter % 3 === 0)) {
                    $isEligibleMilestone = true;
                }
            }

            if (! $isEligibleMilestone) {
                return;
            }

            // Check if covered by a renewal or subsequent active enrollment
            $coveredByRenewal = \App\Models\CoursesEnrollment::where('student_id', $enrollment->student_id)
                ->where('course_id', $enrollment->course_id)
                ->where('id', '!=', $enrollment->id)
                ->where('status', 'active')
                ->where(function ($q) use ($enrollment) {
                    $q->whereNull('end_date')->orWhere('end_date', '>', $enrollment->end_date);
                })
                ->exists();
            if ($coveredByRenewal) {
                return;
            }

            // If on or past expiry, check if a successful payment was made for this course/enrollment
            if ($diffDays <= 0) {
                $hasPaid = \App\Models\Payment::where('student_id', $enrollment->student_id)
                    ->where('status', 'successful')
                    ->where(function ($q) use ($enrollment) {
                        $q->where('course_enrollment_id', $enrollment->id)
                            ->orWhereHas('enrollment', fn ($sq) => $sq->where('course_id', $enrollment->course_id));
                    })
                    ->where('created_at', '>=', $enrollment->end_date->copy()->subHours(24))
                    ->exists();
                if ($hasPaid) {
                    return;
                }
            }

            $student = $enrollment->student;
            $recipients = collect([$student])
                ->concat($student->guardians)
                ->unique(fn ($r) => $r->getMorphClass() . ':' . $r->getKey());

            $expiryIso = $enrollment->end_date->toISOString();
            $notification = new \App\Notifications\SubscriptionExpiryNotification($enrollment, $diffDays, $student);

            foreach ($recipients as $recipient) {
                $deliveryKey = hash('sha256', "sub_reminder|{$enrollment->id}|{$student->id}|{$expiryIso}|{$diffDays}|" . $recipient->getMorphClass() . '|' . $recipient->getKey());

                $claimed = (bool) DB::table('student_activity_deliveries')->insertOrIgnore([
                    'delivery_key' => $deliveryKey,
                    'student_id' => $student->id,
                    'event_type' => $diffDays <= 0 ? 'subscription_expired' : 'subscription_expiring',
                    'recipient_type' => $recipient->getMorphClass(),
                    'recipient_id' => $recipient->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (! $claimed) {
                    continue;
                }

                // In-app database notification
                $recipient->notifyNow($notification, ['database']);

                // Mail delivery after commit
                if (in_array('mail', $notification->via($recipient), true)) {
                    DB::afterCommit(function () use ($recipient, $notification) {
                        try {
                            $mailRecipient = clone $recipient;
                            $mailRecipient->email = trim((string) $mailRecipient->email);
                            $mailRecipient->notifyNow($notification, ['mail']);
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    });
                }
            }
        });
    }

    public static function assessmentReminder(int $assessmentId): void
    {
        DB::transaction(function () use ($assessmentId) {
            $assessment = \App\Models\Assessment::lockForUpdate()->find($assessmentId);
            if (! $assessment || $assessment->status !== 'published' || ! $assessment->due_at
                || $assessment->due_at->lte(now()) || ! $assessment->opens_at || $assessment->opens_at->isFuture()) {
                return;
            }
            $publication = DB::table('notifications')->where('data->type', 'assessment_published')
                ->where('data->data->assessment_id', $assessment->id)->min('created_at');
            $availableFrom = $assessment->opens_at->copy();
            if ($publication && $availableFrom->lt($publication)) {
                $availableFrom = \Carbon\Carbon::parse($publication);
            }
            $duration = $availableFrom->diffInSeconds($assessment->due_at, false);
            $leadMinutes = match (true) {
                $duration > 86400 => 1440,
                $duration > 7200 => 120,
                $duration > 900 => 15,
                default => null,
            };
            if ($leadMinutes === null || now()->lt($assessment->due_at->copy()->subMinutes($leadMinutes))) {
                return;
            }
            self::enrolledStudents($assessment->subject_id)
                ->whereNotIn('id', \App\Models\AssessmentSubmission::select('student_id')->where('assessment_id', $assessment->id)->whereIn('status', ['submitted', 'graded']))
                ->chunkById(100, function ($students) use ($assessment, $leadMinutes) {
                    foreach ($students as $student) {
                        self::learning($student, 'assessment_deadline_reminder', $assessment->id.'|'.$assessment->due_at->toISOString(),
                            new AssessmentNotification('assessment_deadline_reminder', 'Reminder: submit '.$assessment->title.' before it closes.',
                                ['assessment_id' => $assessment->id, 'title' => $assessment->title,
                                    'due_at' => $assessment->due_at->toISOString(), 'reminder_minutes' => $leadMinutes]));
                    }
                });
        });
    }

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
                                    $mailRecipient = clone $recipient;
                                    $mailRecipient->email = trim((string) $mailRecipient->email);
                                    $mailRecipient->notifyNow($studentNotification, ['mail']);
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