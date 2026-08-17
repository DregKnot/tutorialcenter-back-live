<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAttemptActivitySession extends Model
{
    protected $fillable = [
        'exam_attempt_id',
        'student_id',
        'session_token',
        'started_at',
        'ended_at',
        'active_seconds',
        'ended_reason',
        'last_heartbeat_at',
        'metadata',
    ];

    protected $casts = [
        'exam_attempt_id' => 'integer',
        'student_id' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'active_seconds' => 'integer',
        'last_heartbeat_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function examAttempt()
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
