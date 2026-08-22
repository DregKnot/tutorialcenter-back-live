<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentSubjectTrial extends Model
{
    public const STARTED = 'started';
    public const COMPLETED = 'completed';
    public const ABANDONED = 'abandoned';
    public const INVALIDATED = 'invalidated';

    protected $fillable = [
        'student_id',
        'subject_id',
        'exam_attempt_id',
        'status',
        'started_at',
        'completed_at',
        'became_eligible_at',
    ];

    protected $casts = [
        'student_id' => 'integer',
        'subject_id' => 'integer',
        'exam_attempt_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'became_eligible_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function examAttempt()
    {
        return $this->belongsTo(ExamAttempt::class);
    }
}
