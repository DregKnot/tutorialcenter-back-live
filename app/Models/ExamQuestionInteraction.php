<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamQuestionInteraction extends Model
{
    public const VIEWED = 'viewed';
    public const ANSWERED = 'answered';
    public const SKIPPED = 'skipped';
    public const TIMED_OUT = 'timed_out';

    protected $fillable = [
        'exam_attempt_id',
        'student_id',
        'past_question_id',
        'past_question_option_id',
        'sequence_number',
        'action',
        'is_correct',
        'question_shown_at',
        'action_submitted_at',
        'response_duration_ms',
        'client_event_id',
        'metadata',
    ];

    protected $casts = [
        'exam_attempt_id' => 'integer',
        'student_id' => 'integer',
        'past_question_id' => 'integer',
        'past_question_option_id' => 'integer',
        'sequence_number' => 'integer',
        'is_correct' => 'boolean',
        'question_shown_at' => 'datetime',
        'action_submitted_at' => 'datetime',
        'response_duration_ms' => 'integer',
        'metadata' => 'array',
    ];

    public function examAttempt()
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function question()
    {
        return $this->belongsTo(PastQuestion::class, 'past_question_id');
    }

    public function option()
    {
        return $this->belongsTo(
            PastQuestionOption::class,
            'past_question_option_id'
        );
    }
}
