<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAttempt extends Model
{
    const IN_PROGRESS = 'in_progress';
    const COMPLETED = 'completed';
    const ABANDONED = 'abandoned';

    protected $fillable = [
        'student_id',
        'exam_year_id',
        'score',
        'total_questions',
        'correct_answers',
        'wrong_answers',
        'unanswered',
        'percentage',
        'started_at',
        'submitted_at',
        'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'student_id' => 'integer',
        'exam_year_id' => 'integer',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function examYear()
    {
        return $this->belongsTo(ExamYear::class);
    }

    public function answers()
    {
        return $this->hasMany(ExamAttemptAnswer::class);
    }

    public function questionInteractions()
    {
        return $this->hasMany(ExamQuestionInteraction::class);
    }

    public function activitySessions()
    {
        return $this->hasMany(ExamAttemptActivitySession::class);
    }

    public function activityHeartbeats()
    {
        return $this->hasMany(ExamActivityHeartbeat::class);
    }

    public function awardedAchievements()
    {
        return $this->hasMany(StudentAchievement::class);
    }

    public function subjectTrial()
    {
        return $this->hasOne(StudentSubjectTrial::class);
    }

    public function feedbacks()
    {
        return $this->morphMany(
            Feedback::class,
            'feedbackable'
        );
    }
}
