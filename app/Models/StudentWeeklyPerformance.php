<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentWeeklyPerformance extends Model
{
    protected $table = 'student_weekly_performance';

    protected $fillable = [
        'student_id',
        'week_key',
        'week_starts_at',
        'week_ends_at',
        'timezone',
        'completed_attempts',
        'abandoned_attempts',
        'total_questions',
        'correct_answers',
        'wrong_answers',
        'skipped_questions',
        'unanswered_questions',
        'accuracy_percentage',
        'active_seconds',
        'is_eligible',
        'finalized_at',
        'metadata',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'week_starts_at' => 'datetime',
        'week_ends_at' => 'datetime',
        'completed_attempts' => 'integer',
        'abandoned_attempts' => 'integer',
        'total_questions' => 'integer',
        'correct_answers' => 'integer',
        'wrong_answers' => 'integer',
        'skipped_questions' => 'integer',
        'unanswered_questions' => 'integer',
        'accuracy_percentage' => 'decimal:4',
        'active_seconds' => 'integer',
        'is_eligible' => 'boolean',
        'finalized_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
