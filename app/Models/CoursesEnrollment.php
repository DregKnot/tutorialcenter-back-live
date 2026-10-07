<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CoursesEnrollment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'enrollment_code',
        'course_id',
        'student_id',
        'start_date',
        'end_date',
        'expires_at',
        'paid_at',
        'billing_cycle',
        'cost',
        'status',
        'termination_reason',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'cost' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Helper Methods for Enrollment Codes & Expiration
    |--------------------------------------------------------------------------
    */

    public static function generateTemporaryCode(): string
    {
        do {
            $code = 'TMP-ENR-' . date('Y') . '-' . strtoupper(Str::random(5));
        } while (static::where('enrollment_code', $code)->exists());

        return $code;
    }

    public static function generatePermanentCode(int $enrollmentId): string
    {
        return 'ENR-' . date('Y') . '-' . str_pad($enrollmentId, 5, '0', STR_PAD_LEFT);
    }

    public function isExpired(): bool
    {
        if (in_array($this->status, ['expired', 'terminated'])) {
            return true;
        }
        if ($this->status === 'pending' && $this->expires_at && $this->expires_at->isPast()) {
            return true;
        }
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function course()
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subjects()
    {
        return $this->hasMany(SubjectsEnrollment::class, 'course_enrollment_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'course_enrollment_id');
    }

    public function subjectsEnrollments()
    {
        return $this->hasMany(SubjectsEnrollment::class, 'course_enrollment_id');
    }

    public function isActive(): bool
    {
        return
            $this->status === 'active'
            && (
                is_null($this->end_date)
                || $this->end_date->gte(now())
            )
            && $this->payments()
            ->whereIn('status', ['successful', 'paid'])
            ->exists()
            && $this->subjects()
            ->whereNull('deleted_at')
            ->exists();
    }

    public function scopeActiveSubscription($query)
    {
        return $query
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }
}
