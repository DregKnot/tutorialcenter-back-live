<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    ];

    protected $casts = [
        'marks' => 'float',
        'order' => 'integer',
    ];

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function options()
    {
        return $this->hasMany(AssessmentQuestionOption::class, 'question_id')->orderBy('id');
    }

    public function answers()
    {
        return $this->hasMany(AssessmentAnswer::class, 'question_id');
    }
}
