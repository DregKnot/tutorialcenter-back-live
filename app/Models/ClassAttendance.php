<?php

namespace App\Models;

use App\Services\StudentNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassAttendance extends Model
{
    use SoftDeletes;

    protected $table = 'class_attendances';

    protected $fillable = [
        'class_session_id',
        'student_id',
        'joined_at',
        'attendance_duration',
        'left_at',
        'last_seen_at', 'visit_started_at', 'connection_state', 'visit_number', 'connected_seconds',
        'status',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'visit_started_at' => 'datetime',
        'visit_number' => 'integer',
        'connected_seconds' => 'integer',
        'joined_at' => 'datetime',
        'attendance_duration' => 'datetime',
        'left_at' => 'datetime',
    ];

    protected $appends = [
        'rejoin_count',
        'duration_minutes',
    ];

    public function getRejoinCountAttribute(): int
    {
        return max(0, ((int) $this->visit_number) - 1);
    }

    public function getDurationMinutesAttribute(): int
    {
        if ($this->connected_seconds > 0) {
            return (int) floor($this->connected_seconds / 60);
        }
        if ($this->joined_at && $this->left_at) {
            return max(1, (int) $this->joined_at->diffInMinutes($this->left_at));
        }
        return 0;
    }


    public static function timeoutMinutes(): int
    {
        return max(5, (int) config('services.student_activity.class_timeout_minutes', 10));
    }

    public function scheduledEnd(): ?Carbon
    {
        $session = $this->session;
        if (! $session || ! $session->session_date || ! $session->ends_at) {
            return null;
        }

        return Carbon::parse($session->session_date->toDateString().' '.$session->ends_at);
    }

    public function activity(string $type): void
    {
        $this->loadMissing(['student', 'session.class.subject']);
        // A reconnect does not send another join alert for the same class.
        $occurrence = 'attendance:'.$this->id.($type === 'class_joined' ? '' : ':visit:'.$this->visit_number);
        StudentNotificationService::activity($this->student, $type, $occurrence, [
            'attendance_id' => $this->id,
            'class_session_id' => $this->class_session_id,
            'subject' => $this->session?->class?->subject?->name ?? 'Scheduled',
            'visit_number' => $this->visit_number,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'duration_minutes' => (int) floor($this->connected_seconds / 60),
            'occurred_at' => ($type === 'class_abandoned' ? $this->last_seen_at : now())?->toISOString(),
        ]);
    }

    /** Caller holds a row lock. Legacy left_at remains the report's last observed time. */
    public function beginVisit(): void
    {
        $this->update([
            'connection_state' => 'active',
            'visit_number' => $this->visit_number + 1,
            'visit_started_at' => now(),
            'last_seen_at' => now(),
            'left_at' => now(),
        ]);
        $this->activity('class_joined');
    }

    public function observeConnection(): void
    {
        $now = now();
        $end = $this->scheduledEnd();
        $observedUntil = $end && $end->lt($now) ? $end : $now;
        $seconds = $this->last_seen_at ? max(0, (int) $this->last_seen_at->diffInSeconds($observedUntil)) : 0;
        // Do not count a long gap without heartbeats as connected time.
        $this->update([
            'connected_seconds' => $this->connected_seconds + ($seconds <= self::timeoutMinutes() * 60 ? $seconds : 0),
            'last_seen_at' => $now,
            'left_at' => $now,
        ]);
    }

    /** Resolve stale or normally ended visits, including when the scheduler runs late. */
    public function closeStaleVisit(): void
    {
        if ($this->connection_state !== 'active' || ! $this->last_seen_at) {
            return;
        }
        $end = $this->scheduledEnd();
        $expires = $this->last_seen_at->copy()->addMinutes(self::timeoutMinutes());
        if ($expires->gt(now()) && (! $end || $end->gt(now()))) {
            return;
        }
        $normalEnd = $end && $end->lte(now()) && $expires->gte($end);
        $this->update(['connection_state' => $normalEnd ? 'ended' : 'disconnected']);
        if (! $normalEnd) {
            $this->activity('class_abandoned');
        }
    }

    /**
     * Relationships
     */
    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
