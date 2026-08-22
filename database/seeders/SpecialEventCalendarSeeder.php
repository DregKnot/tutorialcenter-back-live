<?php

namespace Database\Seeders;

use App\Models\SpecialEventCalendar;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SpecialEventCalendarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $events = [
            [
                'country_code' => 'NG',
                'event_code' => 'special_event.new_year_scholar',
                'event_key' => 'NG-new-year-2026',
                'timezone' => 'Africa/Lagos',
                'starts_at' => Carbon::create(2026, 1, 1, 0, 0, 0, 'Africa/Lagos')->utc(),
                'ends_at' => Carbon::create(2026, 1, 1, 23, 59, 59, 'Africa/Lagos')->utc(),
                'minimum_exam_practices_started' => 1,
                'metadata' => [
                    'event' => 'new_year_day',
                    'year' => 2026,
                ],
                'is_active' => true,
            ],

            [
                'country_code' => 'ZA',
                'event_code' => 'special_event.new_year_scholar',
                'event_key' => 'ZA-new-year-2026',
                'timezone' => 'Africa/Johannesburg',
                'starts_at' => Carbon::create(2026, 1, 1, 0, 0, 0, 'Africa/Johannesburg')->utc(),
                'ends_at' => Carbon::create(2026, 1, 1, 23, 59, 59, 'Africa/Johannesburg')->utc(),
                'minimum_exam_practices_started' => 1,
                'metadata' => [
                    'event' => 'new_year_day',
                    'year' => 2026,
                ],
                'is_active' => true,
            ],

            [
                'country_code' => 'NG',
                'event_code' => 'special_event.easter_excellence',
                'event_key' => 'NG-easter-2026',
                'timezone' => 'Africa/Lagos',
                'starts_at' => Carbon::create(2026, 4, 5, 0, 0, 0, 'Africa/Lagos')->utc(),
                'ends_at' => Carbon::create(2026, 4, 5, 23, 59, 59, 'Africa/Lagos')->utc(),
                'minimum_exam_practices_started' => 1,
                'metadata' => [
                    'event' => 'easter_sunday',
                    'year' => 2026,
                ],
                'is_active' => true,
            ],

            [
                'country_code' => 'NG',
                'event_code' => 'special_event.independence_day_challenge',
                'event_key' => 'NG-independence-day-2026',
                'timezone' => 'Africa/Lagos',
                'starts_at' => Carbon::create(2026, 10, 1, 0, 0, 0, 'Africa/Lagos')->utc(),
                'ends_at' => Carbon::create(2026, 10, 1, 23, 59, 59, 'Africa/Lagos')->utc(),
                'minimum_exam_practices_started' => 1,
                'metadata' => [
                    'event' => 'independence_day',
                    'year' => 2026,
                ],
                'is_active' => true,
            ],

            [
                'country_code' => 'NG',
                'event_code' => 'special_event.christmas_learning_champion',
                'event_key' => 'NG-christmas-2026',
                'timezone' => 'Africa/Lagos',
                'starts_at' => Carbon::create(2026, 12, 25, 0, 0, 0, 'Africa/Lagos')->utc(),
                'ends_at' => Carbon::create(2026, 12, 25, 23, 59, 59, 'Africa/Lagos')->utc(),
                'minimum_exam_practices_started' => 1,
                'metadata' => [
                    'event' => 'christmas_day',
                    'year' => 2026,
                ],
                'is_active' => true,
            ],
        ];

        foreach ($events as $event) {
            SpecialEventCalendar::updateOrCreate(
                [
                    'country_code' => $event['country_code'],
                    'event_code' => $event['event_code'],
                    'event_key' => $event['event_key'],
                    'timezone' => $event['timezone'],
                ],
                $event
            );
        }
    }
}
