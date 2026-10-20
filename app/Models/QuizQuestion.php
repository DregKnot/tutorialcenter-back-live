<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class QuizQuestion extends Model
{
    use SoftDeletes;

    const TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    const TYPE_TRUE_FALSE      = 'true_false';
    const TYPE_SHORT_ANSWER    = 'short_answer';

    protected $fillable = [
        'quiz_id',
        'type',
        'question',
        'marks',
        'order',
        'explanation',
        'image',
    ];

    protected $appends = ['image_url'];

    protected $casts = [
        'marks' => 'float',
        'order' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The quiz this question belongs to.
     */
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * The answer options for multiple_choice / true_false questions.
     */
    public function options()
    {
        return $this->hasMany(QuizQuestionOption::class, 'question_id')->orderBy('order');
    }

    /**
     * The student answers recorded against this question.
     */
    public function answers()
    {
        return $this->hasMany(QuizAnswer::class, 'question_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Signed URL to display the optional reference image.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image
            ? Storage::disk('local')->temporaryUrl($this->image, now()->addMinutes(60))
            : null;
    }
}
