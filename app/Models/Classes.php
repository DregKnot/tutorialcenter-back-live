<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classes extends Model
{
    use SoftDeletes;

    protected $table = 'classes';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'subject_id',
        'title',
        'description',
        'status', // consider using ENUM in migration for active/inactive etc.
        'is_private',
        'private_student_id',
        'zoom_meeting_id',
        'zoom_meeting_password',
        'zoom_join_url',
        'zoom_start_url',
    ];

    protected $casts = [
        'is_private' => 'boolean',
    ];

    /**
     * Relationships
     */

    // Staffs assigned to this class (many-to-many pivot)
    public function staffs()
    {
        return $this->belongsToMany(
            Staff::class,
            'class_staff',   // pivot table
            'class_id',      // foreign key on pivot referencing Classes
            'staff_id'       // foreign key on pivot referencing Staff
        )
            ->using(ClassStaff::class) // pivot model
            ->withPivot('role')
            ->withTimestamps();
    }

    // Class schedules (one-to-many)
    public function schedules()
    {
        return $this->hasMany(ClassSchedule::class, 'class_id');
    }

    // Individual class sessions (one-to-many)
    public function sessions()
    {
        return $this->hasMany(ClassSession::class, 'class_id');
    }

    // Subject (each class belongs to a subject)
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    // Assessments created for this class
    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'class_id');
    }

    // Private student assigned to this 1-on-1 class
    public function privateStudent()
    {
        return $this->belongsTo(Student::class, 'private_student_id');
    }

    // Private class enrollment & payment metadata
    public function privateEnrollment()
    {
        return $this->hasOne(PrivateClassEnrollment::class, 'class_id');
    }

    /**
     * Scopes
     */
    public function scopePublicClasses($query)
    {
        return $query->where('is_private', false);
    }

    public function scopePrivateClasses($query)
    {
        return $query->where('is_private', true);
    }

    public function scopeForStudent($query, $student)
    {
        return $query->where(function ($q) use ($student) {
            $q->where('is_private', false)
              ->orWhere('private_student_id', $student->id);
        });
    }

    // Optional: students enrolled in this class via sessions/enrollments
    public function students()
    {
        return $this->hasManyThrough(
            Student::class,
            ClassSession::class,
            'class_id',        // Foreign key on ClassSession
            'id',              // Foreign key on Student
            'id',              // Local key on Classes
            'student_id'       // Local key on ClassSession
        );
    }
}
