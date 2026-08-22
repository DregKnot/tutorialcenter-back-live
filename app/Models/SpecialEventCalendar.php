<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialEventCalendar extends Model
{
    protected $fillable = [
        'country_code',
        'event_code',
        'event_key',
        'starts_at',
        'ends_at',
        'timezone',
        'minimum_exam_practices_started',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'minimum_exam_practices_started' => 'integer',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry($query, string $countryCode)
    {
        return $query->where(
            'country_code',
            strtoupper($countryCode)
        );
    }

    public function scopeEndedBy($query, $date)
    {
        return $query->where('ends_at', '<=', $date);
    }
}
