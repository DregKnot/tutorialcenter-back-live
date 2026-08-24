<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAchievement extends Model
{
    protected $fillable = [
        'student_id',
        'achievement_id',
        'exam_attempt_id',
        'subject_id',
        'tier',
        'period_key',
        'occurrence_key',
        'metadata',
        'awarded_at',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'achievement_id' => 'integer',
        'exam_attempt_id' => 'integer',
        'subject_id' => 'integer',
        'metadata' => 'array',
        'awarded_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function achievement()
    {
        return $this->belongsTo(Achievement::class);
    }

    public function examAttempt()
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
