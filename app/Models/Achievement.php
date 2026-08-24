<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'type',
        'tier',
        'scope',
        'repeatable',
        'progressive',
        'display_order',
        'icon_path',
        'requirements',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'repeatable' => 'boolean',
        'progressive' => 'boolean',
        'display_order' => 'integer',
        'requirements' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function studentAchievements()
    {
        return $this->hasMany(StudentAchievement::class);
    }
}
