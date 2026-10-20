<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonProgress extends Model
{
    const NOT_STARTED  = 'not_started';
    const IN_PROGRESS  = 'in_progress';
    const COMPLETED    = 'completed';

    protected $table = 'lesson_progress';

    protected $fillable = [
        'student_id',
        'lesson_id',
        'module_id',
        'subject_id',
        'status',
        'started_at',
        'completed_at',
        'time_spent_seconds',
        'completion_percentage',
    ];

    protected $casts = [
        'started_at'            => 'datetime',
        'completed_at'          => 'datetime',
        'time_spent_seconds'    => 'integer',
        'completion_percentage' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The student this progress record belongs to.
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The lesson being tracked.
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * The module the lesson belongs to.
     */
    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * The subject this progress is under.
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeCompleted($query)
    {
        return $query->where('status', self::COMPLETED);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::IN_PROGRESS);
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForSubject($query, int $subjectId)
    {
        return $query->where('subject_id', $subjectId);
    }
}
