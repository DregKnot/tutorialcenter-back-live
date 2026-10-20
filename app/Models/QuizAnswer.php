<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    protected $fillable = [
        'submission_id',
        'question_id',
        'selected_option_id',
        'text_answer',
        'is_correct',
        'marks_awarded',
    ];

    protected $casts = [
        'is_correct'    => 'boolean',
        'marks_awarded' => 'float',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The quiz submission this answer belongs to.
     */
    public function submission()
    {
        return $this->belongsTo(QuizSubmission::class, 'submission_id');
    }

    /**
     * The question being answered.
     */
    public function question()
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }

    /**
     * The selected answer option (for multiple_choice / true_false).
     */
    public function selectedOption()
    {
        return $this->belongsTo(QuizQuestionOption::class, 'selected_option_id');
    }
}
