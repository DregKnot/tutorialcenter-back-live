<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssessmentSubmission extends Model
{
    use SoftDeletes;

    const IN_PROGRESS = 'in_progress';
    const SUBMITTED = 'submitted';
    const GRADED = 'graded';
    const ABSENT = 'absent';

    protected $fillable = [
        'assessment_id',
        'student_id',
        'status',
        'submitted_at',
        'score',
        'total_marks',
        'percentage',
        'graded_by',
        'graded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'score' => 'float',
        'total_marks' => 'float',
        'percentage' => 'float',
    ];

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function grader()
    {
        return $this->belongsTo(Staff::class, 'graded_by');
    }

    public function answers()
    {
        return $this->hasMany(AssessmentAnswer::class, 'submission_id');
    }
}
