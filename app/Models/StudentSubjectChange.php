<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSubjectChange extends Model
{
    protected $table = 'student_subject_changes';

    protected $fillable = [
        'student_id',
        'course_enrollment_id',
        'action_type',
        'old_subject_id',
        'new_subject_id',
        'reason',
        'changed_by_staff_id',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CoursesEnrollment::class, 'course_enrollment_id');
    }

    public function oldSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'old_subject_id');
    }

    public function newSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'new_subject_id');
    }

    public function changedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'changed_by_staff_id');
    }
}

