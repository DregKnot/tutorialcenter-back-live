<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Blog extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'blog_category_id',
        'author_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'images',
        'reading_time',
        'views',
        'is_featured',
        'allow_comments',
        'status',
        'published_at',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_featured' => 'boolean',
        'allow_comments' => 'boolean',
        'images' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($blog) {
            if (empty($blog->slug)) {
                $blog->slug = Str::slug($blog->title);
            }
        });

        static::updating(function ($blog) {
            if ($blog->isDirty('title')) {
                $blog->slug = Str::slug($blog->title);
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function author()
    {
        return $this->belongsTo(Staff::class, 'author_id');
    }

    public function tags()
    {
        return $this->belongsToMany(BlogTag::class);
    }

    public function comments()
    {
        return $this->hasMany(BlogComment::class);
    }

    public function getFeaturedImageAttribute($value)
    {
        if (empty($value)) {
            return $value;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, 'data:') || str_starts_with($value, 'blob:')) {
            return $value;
        }

        $baseUrl = rtrim(config('app.url', url('/')), '/');
        $cleanPath = '/' . ltrim($value, '/');
        return $baseUrl . $cleanPath;
    }

    public function getImagesAttribute($value)
    {
        $baseUrl = rtrim(config('app.url', url('/')), '/');
        $list = [];

        if (!empty($value)) {
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            if (is_array($decoded) && count($decoded) > 0) {
                $list = array_values($decoded);
            }
        }

        if (empty($list) && !empty($this->attributes['featured_image'] ?? null)) {
            $list = [$this->attributes['featured_image']];
        }

        return array_map(function ($img) use ($baseUrl) {
            if (empty($img)) return $img;
            if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://') || str_starts_with($img, 'data:') || str_starts_with($img, 'blob:')) {
                return $img;
            }
            return $baseUrl . '/' . ltrim($img, '/');
        }, $list);
    }

    public function getContentAttribute($value)
    {
        if (empty($value)) {
            return $value;
        }

        $baseUrl = rtrim(config('app.url', url('/')), '/');

        return preg_replace_callback(
            '/(<(?:img|source|video|audio)[^>]*\s+src=["\'])(?:\/)?storage\/([^"\']+)(["\'])/i',
            function ($matches) use ($baseUrl) {
                return $matches[1] . $baseUrl . '/storage/' . $matches[2] . $matches[3];
            },
            $value
        );
    }
}