<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Staff extends Authenticatable
{
    use SoftDeletes, Notifiable, HasApiTokens;

    protected $table = 'staffs';

    protected $fillable = [
        'staff_id',
        'firstname',
        'middlename',
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
        'role',
        'inducted_by',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'date_of_birth' => 'date',
        'email_verified_at' => 'datetime',
        'tel_verified_at' => 'datetime',
    ];

    public function emailVerification()
    {
        return $this->morphOne(EmailVerification::class, 'verifiable');
    }

    public function advisees()
    {
        return $this->belongsToMany(Student::class, 'student_advisors')
            ->withPivot(['role', 'assigned_at'])
            ->withTimestamps();
    }

    // Assessments created by this staff member
    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'created_by');
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    // Each staff member can have many feedbacks
    public function feedbacks()
    {
        return $this->morphMany(
            Feedback::class,
            'feedbackable'
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
