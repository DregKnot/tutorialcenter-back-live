<?php

namespace App\Console\Commands;

use App\Models\CoursesEnrollment;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;
use Log;

class SendSubscriptionExpiryReminders extends Command
{
    protected $signature = 'subscriptions:send-expiry-reminders';

    protected $description = 'Send subscription expiry reminders (pre-expiry & post-expiry) to students and guardians';

    public function handle(): int
    {
        $failures = 0;
        $tz = config('app.timezone', 'Africa/Lagos');

        // Look back up to 31 days (for the 30-day post-expiry window) and forward up to 8 days (for the 7-day pre-expiry window)
        $minDate = now()->timezone($tz)->subDays(31)->startOfDay();
        $maxDate = now()->timezone($tz)->addDays(8)->endOfDay();

        CoursesEnrollment::whereIn('status', ['active', 'expired'])
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$minDate, $maxDate])
            ->chunkById(100, function ($records) use (&$failures) {
                foreach ($records as $record) {
                    try {
                        StudentNotificationService::subscriptionReminder($record->id);
                    } catch (\Throwable $exception) {
                        $failures++;
                        Log::error('Subscription expiry reminder failed', [
                            'enrollment_id' => $record->id,
                            'message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ]);
                        report($exception);
                    }
                }
            });

        $this->info('Subscription reminder check completed. Processing failures: '.$failures.'.');
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
