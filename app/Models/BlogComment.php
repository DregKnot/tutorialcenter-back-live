<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlogComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'blog_id',

        'commenter_type',
        'commenter_id',

        'guest_name',
        'guest_email',
        'guest_website',

        'comment',

        'status',

        'ip_address',
        'user_agent',
    ];

    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }

    public function commenter()
    {
        return $this->morphTo();
    }

    /**
     * Display name
     */
    public function getNameAttribute()
    {
        if ($this->commenter) {
            return trim(
                ($this->commenter->firstname ?? '') .
                ' ' .
                ($this->commenter->surname ?? '')
            );
        }

        return $this->guest_name;
    }

    /**
     * Display email
     */
    public function getEmailAttribute()
    {
        return $this->commenter->email ?? $this->guest_email;
    }
}