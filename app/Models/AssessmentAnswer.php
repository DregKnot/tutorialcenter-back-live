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

    /**
     * The submission this answer belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function submission()
    {
        return $this->belongsTo(AssessmentSubmission::class);
    }

    /**
     * The assessment question this answer responds to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function question()
    {
        return $this->belongsTo(AssessmentQuestion::class);
    }

    /**
     * The selected option for a multiple-choice answer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function option()
    {
        return $this->belongsTo(AssessmentQuestionOption::class, 'question_option_id');
    }

    /**
     * The uploaded image files attached to a paper-submission answer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function files()
    {
        return $this->hasMany(AssessmentAnswerFile::class, 'answer_id');
    }
}
