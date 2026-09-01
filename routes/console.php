<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('users:delete-unverified')->hourly();

Schedule::command('exam:mark-abandoned')->everyTenMinutes();

Schedule::command('assessment:mark-unattended')->everyTenMinutes();

Schedule::command(
    'achievements:finalize-weekly-accuracy --timezone=Africa/Lagos'
)
    ->weeklyOn(1, '00:15')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(120);

Schedule::command('achievements:evaluate-ranks')
    ->dailyAt('00:30')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(120);

Schedule::command('achievements:evaluate-leaderboard')
    ->dailyAt('00:45')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping(120);

Schedule::command('achievements:evaluate-special-events')
    ->hourlyAt(10)
    ->withoutOverlapping(120);
