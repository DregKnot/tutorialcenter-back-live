<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassSessionView extends Model
{
    protected $table = 'class_session_views';

    protected $fillable = [
        'class_session_id',
        'student_id',
        'view_count',
        'first_viewed_at',
        'last_viewed_at',
    ];

    protected $casts = [
        'view_count' => 'integer',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
