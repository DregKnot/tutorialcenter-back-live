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

    public function getModelImageUrlAttribute(): ?string
    {
        return $this->model_image
            ? Storage::disk('local')->temporaryUrl($this->model_image, now()->addMinutes(60))
            : null;
    }
}
