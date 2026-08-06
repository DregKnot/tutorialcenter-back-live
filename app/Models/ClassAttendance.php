<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassAttendance extends Model
{
    use SoftDeletes;

    protected $table = 'class_attendances';

    protected $fillable = [
        'class_session_id',
        'student_id',
        'joined_at',
        'attendance_duration',
        'left_at',
        'status'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'attendance_duration' => 'datetime',
        'left_at' => 'datetime'
    ];

    /**
     * Relationships
     */

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}