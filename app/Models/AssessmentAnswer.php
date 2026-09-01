<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentAnswer extends Model
{
    protected $fillable = [
        'submission_id',
        'question_id',
        'question_option_id',
        'answer',
        'is_correct',
        'marks_awarded',
        'feedback',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'marks_awarded' => 'float',
    ];

    public function submission()
    {
        return $this->belongsTo(AssessmentSubmission::class);
    }

    public function question()
    {
        return $this->belongsTo(AssessmentQuestion::class);
    }

    public function option()
    {
        return $this->belongsTo(AssessmentQuestionOption::class, 'question_option_id');
    }
}
