<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSurvey extends Model
{
    protected $table = 'student_surveys';

    protected $fillable = [
        'student_id',
        'survey_type',
        'full_name',
        'exam_target',
        'subjects_taken',
        'usage_duration',
        'overall_rating',
        'live_classes_rating',
        'tutors_rating',
        'study_materials_rating',
        'cbt_rating',
        'platform_rating',
        'navigation_ease',
        'courses_awareness',
        'courses_utility_rating',
        'preferred_video_format',
        'preferred_video_length',
        'nps_score',
        'wants_followup',
        'whatsapp_number',
        'responses',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'subjects_taken' => 'array',
        'responses' => 'array',
        'wants_followup' => 'boolean',
        'overall_rating' => 'integer',
        'live_classes_rating' => 'integer',
        'tutors_rating' => 'integer',
        'study_materials_rating' => 'integer',
        'cbt_rating' => 'integer',
        'platform_rating' => 'integer',
        'courses_utility_rating' => 'integer',
        'nps_score' => 'integer',
    ];

    /**
     * Relationship to Student
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Scopes
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeByExam($query, string $exam)
    {
        return $query->where('exam_target', $exam);
    }
}
