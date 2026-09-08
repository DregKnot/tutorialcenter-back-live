<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class AssessmentQuestion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'assessment_id',
        'type',
        'question',
        'marks',
        'order',
        'explanation',
        'model_image',
    ];

    protected $appends = ['model_image_url'];

    protected $casts = [
        'marks' => 'float',
        'order' => 'integer',
    ];

    /**
     * The assessment this question belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * The answer options for a multiple-choice question.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function options()
    {
        return $this->hasMany(AssessmentQuestionOption::class, 'question_id')->orderBy('id');
    }

    /**
     * The student answers recorded against this question.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function answers()
    {
        return $this->hasMany(AssessmentAnswer::class, 'question_id');
    }

    /**
     * Signed URL used to display the tutor's reference image.
     */
    public function getModelImageUrlAttribute(): ?string
    {
        return $this->model_image
            ? Storage::disk('local')->temporaryUrl($this->model_image, now()->addMinutes(60))
            : null;
    }
}
