<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use SoftDeletes;

    const DRAFT     = 'draft';
    const PUBLISHED = 'published';
    const ARCHIVED  = 'archived';

    protected $fillable = [
        'lesson_id',
        'created_by',
        'title',
        'description',
        'instructions',
        'total_marks',
        'pass_mark',
        'timer_minutes',
        'max_attempts',
        'show_answers_after_submission',
        'status',
    ];

    protected $casts = [
        'total_marks'                    => 'float',
        'pass_mark'                      => 'float',
        'timer_minutes'                  => 'integer',
        'max_attempts'                   => 'integer',
        'show_answers_after_submission'  => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The lesson this quiz belongs to.
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * The staff member who created this quiz.
     */
    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * The questions attached to this quiz, in display order.
     */
    public function questions()
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order');
    }

    /**
     * All student submissions for this quiz.
     */
    public function submissions()
    {
        return $this->hasMany(QuizSubmission::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePublished($query)
    {
        return $query->where('status', self::PUBLISHED);
    }
}
