<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrivateClassEnrollment extends Model
{
    use SoftDeletes;

    protected $table = 'private_class_enrollments';

    protected $fillable = [
        'class_id',
        'student_id',
        'course_enrollment_id',
        'amount_paid',
        'currency',
        'payment_reference',
        'payment_method',
        'session_quota',
        'sessions_completed',
        'starts_at',
        'expires_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'session_quota' => 'integer',
        'sessions_completed' => 'integer',
        'starts_at' => 'date',
        'expires_at' => 'date',
    ];

    /**
     * Relationships
     */
    public function class()
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function courseEnrollment()
    {
        return $this->belongsTo(CoursesEnrollment::class, 'course_enrollment_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if this enrollment has remaining sessions and is usable
     */
    public function hasRemainingSessions(): bool
    {
        return $this->sessions_completed < $this->session_quota && $this->status === 'active';
    }

    /**
     * Increment completed session count and auto-complete if quota reached
     */
    public function markSessionCompleted(): void
    {
        $this->increment('sessions_completed');
        if ($this->sessions_completed >= $this->session_quota) {
            $this->update(['status' => 'completed']);
        }
    }
}

