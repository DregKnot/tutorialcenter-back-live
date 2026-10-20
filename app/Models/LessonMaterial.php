<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class LessonMaterial extends Model
{
    use SoftDeletes;

    const TYPE_VIDEO    = 'video';
    const TYPE_DOCUMENT = 'document';
    const TYPE_LINK     = 'link';
    const TYPE_IMAGE    = 'image';
    const TYPE_AUDIO    = 'audio';

    protected $fillable = [
        'lesson_id',
        'title',
        'type',
        'url',
        'file_path',
        'duration_seconds',
        'order',
        'is_preview',
    ];

    protected $appends = ['file_url'];

    protected $casts = [
        'duration_seconds' => 'integer',
        'order'            => 'integer',
        'is_preview'       => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The lesson this material belongs to.
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Returns a signed/temporary URL for stored files, or the raw URL for external links.
     */
    public function getFileUrlAttribute(): ?string
    {
        if ($this->file_path) {
            try {
                if (Storage::disk('local')->exists($this->file_path)) {
                    return Storage::disk('local')->temporaryUrl($this->file_path, now()->addMinutes(60));
                }
            } catch (\Throwable $e) {
                return url("api/lesson-materials/{$this->id}/download");
            }
        }

        return $this->url;
    }
}
