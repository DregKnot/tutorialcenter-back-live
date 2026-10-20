<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes;

    const DRAFT = 'draft';
    const PUBLISHED = 'published';
    const ARCHIVED = 'archived';

    protected $fillable = [
        'module_id',
        'created_by',
        'title',
        'description',
        'order',
        'duration_minutes',
        'status',
    ];

    protected $casts = [
        'order'            => 'integer',
        'duration_minutes' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The module this lesson belongs to.
     */
    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * The staff member who created this lesson.
     */
    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * The optional materials (videos, docs, links) attached to this lesson.
     */
    public function materials()
    {
        return $this->hasMany(LessonMaterial::class)->orderBy('order');
    }

    /**
     * The optional quizzes attached to this lesson.
     * A lesson can have multiple quizzes.
     */
    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    /**
     * Student progress records for this lesson.
     */
    public function progressRecords()
    {
        return $this->hasMany(LessonProgress::class);
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
