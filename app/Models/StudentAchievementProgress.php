<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAchievementProgress extends Model
{
    protected $table = 'student_achievement_progress';

    protected $fillable = [
        'student_id',
        'progress_key',
        'subject_id',
        'integer_value',
        'decimal_value',
        'duration_seconds',
        'metadata',
        'last_processed_event_id',
        'calculated_at',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'subject_id' => 'integer',
        'integer_value' => 'integer',
        'decimal_value' => 'decimal:4',
        'duration_seconds' => 'integer',
        'metadata' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
