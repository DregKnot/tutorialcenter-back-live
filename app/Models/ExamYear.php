<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamYear extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'exam_body_id',
        'subject_id',
        'year',
        'status',
        'has_paper_types',
        'paper_types',
    ];

    protected $casts = [
        'year' => 'integer',
        'has_paper_types' => 'boolean',
        'paper_types' => 'array',
    ];

    public function examBody()
    {
        return $this->belongsTo(ExamBody::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function pastQuestions()
    {
        return $this->hasMany(PastQuestion::class);
    }

    public function groups()
    {
        return $this->hasMany(PastQuestionGroup::class);
    }

    public function feedbacks()
    {
        return $this->morphMany(
            Feedback::class,
            'feedbackable'
        );
    }
}
