<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assessment extends Model
{
    use SoftDeletes;

    const DRAFT = 'draft';
    const PUBLISHED = 'published';
    const CLOSED = 'closed';

    protected $fillable = [
        'class_id',
        'subject_id',
        'created_by',
        'title',
        'description',
        'instructions',
        'opens_at',
        'due_at',
        'status',
        'total_marks',
        'pass_mark',
        'timer_minutes',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'due_at' => 'datetime',
        'total_marks' => 'float',
        'pass_mark' => 'float',
        'timer_minutes' => 'integer',
    ];

    public function class()
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function questions()
    {
        return $this->hasMany(AssessmentQuestion::class)->orderBy('order');
    }

    public function submissions()
    {
        return $this->hasMany(AssessmentSubmission::class);
    }

    public function subjectEnrollments()
    {
        return $this->hasMany(SubjectsEnrollment::class, 'subject_id', 'subject_id');
    }

    public function students()
    {
        return $this->hasManyThrough(
            Student::class,
            SubjectsEnrollment::class,
            'subject_id',
            'id',
            'subject_id',
            'student_id'
        );
    }
}
