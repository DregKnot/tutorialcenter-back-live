<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class AssessmentAnswerFile extends Model
{
    protected $fillable = [
        'answer_id',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
    ];

    protected $appends = ['url'];

    public function answer()
    {
        return $this->belongsTo(AssessmentAnswer::class);
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->file_path || ! Storage::disk('local')->exists($this->file_path)) {
            return null;
        }

        return Storage::disk('local')->temporaryUrl($this->file_path, now()->addMinutes(60));
    }
}
