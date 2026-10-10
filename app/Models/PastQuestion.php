<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PastQuestion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'exam_year_id',
        'past_question_group_id',
        'question_number',
        'question',
        'question_type',
        'paper_type',
        'marks',
        'explanation',
        'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'exam_year_id' => 'integer',
        'question_number' => 'integer',
        'marks' => 'integer',
    ];

    public function examYear()
    {
        return $this->belongsTo(ExamYear::class);
    }

    public function group()
    {
        return $this->belongsTo(PastQuestionGroup::class, 'past_question_group_id');
    }

    public function options()
    {
        return $this->hasMany(PastQuestionOption::class);
    }

    public function files()
    {
        return $this->hasMany(PastQuestionFile::class);
    }
}
