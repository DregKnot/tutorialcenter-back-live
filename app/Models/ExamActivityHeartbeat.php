<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamActivityHeartbeat extends Model
{
    protected $fillable = [
        'exam_attempt_id',
        'student_id',
        'session_token',
        'question_id',
        'client_event_id',
        'page_visible',
        'app_focused',
        'interaction_type',
        'occurred_at',
        'received_at',
        'metadata',
    ];

    protected $casts = [
        'exam_attempt_id' => 'integer',
        'student_id' => 'integer',
        'question_id' => 'integer',
        'page_visible' => 'boolean',
        'app_focused' => 'boolean',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
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

    public function question()
    {
        return $this->belongsTo(PastQuestion::class, 'question_id');
    }
}
