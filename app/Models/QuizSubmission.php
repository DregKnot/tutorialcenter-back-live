<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizSubmission extends Model
{
    use SoftDeletes;

    const IN_PROGRESS = 'in_progress';
    const SUBMITTED   = 'submitted';
    const GRADED      = 'graded';

    protected $fillable = [
        'quiz_id',
        'student_id',
        'attempt_number',
        'status',
        'started_at',
        'submitted_at',
        'score',
        'total_marks',
        'percentage',
        'passed',
    ];

    protected $casts = [
        'started_at'     => 'datetime',
        'submitted_at'   => 'datetime',
        'score'          => 'float',
        'total_marks'    => 'float',
        'percentage'     => 'float',
        'passed'         => 'boolean',
        'attempt_number' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The quiz this submission belongs to.
     */
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * The student who submitted this.
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The per-question answers within this submission.
     */
    public function answers()
    {
        return $this->hasMany(QuizAnswer::class, 'submission_id');
    }
}
