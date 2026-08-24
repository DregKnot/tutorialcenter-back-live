<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes, Notifiable, HasApiTokens;

    protected $fillable = [
        'firstname',
        'surname',
        'email',
        'tel',
        'password',
        'gender',
        'profile_picture',
        'date_of_birth',
        'email_verified_at',
        'tel_verified_at',
        'location',
        'address',
        'department',
    ];

    protected $hidden = [
        'password'
    ];

    protected $casts = [
        // 'password' => 'hashed',
        'date_of_birth' => 'date',
        'email_verified_at' => 'datetime',
        'tel_verified_at' => 'datetime',
    ];

    // Each student can have one email verification record
    public function emailVerification()
    {
        return $this->morphOne(EmailVerification::class, 'verifiable');
    }

    // Each student can have many course enrollments
    public function courseEnrollments()
    {
        return $this->hasMany(CoursesEnrollment::class, 'student_id');
    }

    // Each student can have many subject enrollments
    public function subjectEnrollments()
    {
        return $this->hasMany(SubjectsEnrollment::class, 'student_id');
    }

    // Each student can have many payments
    public function payments()
    {
        return $this->hasMany(Payment::class, 'student_id');
    }

    // Each student can have many attendances
    public function attendances()
    {
        return $this->hasMany(ClassAttendance::class, 'student_id');
    }

    public function advisors()
    {
        return $this->belongsToMany(Staff::class, 'student_advisors')
            ->withPivot(['role', 'assigned_at'])
            ->withTimestamps();
    }

    public function guardians()
    {
        return $this->belongsToMany(
            Guardian::class,
            'guardian_students',
            'student_id',
            'guardian_id'
        )
            ->using(GuardianStudent::class)
            ->withPivot('relationship')
            ->withTimestamps();
    }

    public function enrolledInSubject($subjectId): bool
    {
        return $this->subjectEnrollments()
            ->where('subject_id', $subjectId)
            ->exists();
    }


    public function examAttempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function achievements()
    {
        return $this->hasMany(StudentAchievement::class);
    }

    public function achievementProgress()
    {
        return $this->hasMany(StudentAchievementProgress::class);
    }

    public function examQuestionInteractions()
    {
        return $this->hasMany(ExamQuestionInteraction::class);
    }

    public function examActivitySessions()
    {
        return $this->hasMany(ExamAttemptActivitySession::class);
    }

    public function examActivityHeartbeats()
    {
        return $this->hasMany(ExamActivityHeartbeat::class);
    }

    public function subjectTrials()
    {
        return $this->hasMany(StudentSubjectTrial::class);
    }

    public function hasActiveCourseSubscription($courseId): bool
    {
        return $this->courseEnrollments()
            ->activeSubscription()
            ->where('course_id', $courseId)
            ->whereHas('payments', function ($query) {
                $query->where('status', 'successful');
            })
            ->exists();
    }

    public function canAccessExam($examYearId): bool
    {
        $examYear = ExamYear::with([
            'examBody.course',
            'subject'
        ])->find($examYearId);

        if (!$examYear) {
            return false;
        }

        return
            $this->hasActiveCourseSubscription(
                $examYear->examBody->course_id
            )
            &&
            $this->enrolledInSubject(
                $examYear->subject_id
            );
    }

    // Each student can have many feedbacks
    public function feedbacks()
    {
        return $this->morphMany(
            Feedback::class,
            'feedbacker'
        );
    }

    
    public function supportTickets()
    {
        return $this->morphMany(
            SupportTicket::class,
            'requester'
        );
    }

    public function supportMessages()
    {
        return $this->morphMany(
            SupportTicketMessage::class,
            'sender'
        );
    }
}
