<?php

namespace App\Console\Commands;

use App\Models\CoursesEnrollment;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;

class SendSubscriptionExpiryReminders extends Command
{
    protected $signature = 'subscriptions:send-expiry-reminders';

    protected $description = 'Send due student reminders through in-app notifications and email';

    public function handle(): int
    {
        $failures = 0;
        CoursesEnrollment::where('status', 'active')->where('end_date', '>', now())->where('end_date', '<=', now()->addDays(7))
            ->chunkById(100, function ($records) use (&$failures) {
                foreach ($records as $record) {
                    try {
                        StudentNotificationService::subscriptionReminder($record->id);
                    } catch (\Throwable $exception) {
                        $failures++;
                        report($exception);
                    }
                }
            });
        $this->info('Reminder check completed. Processing failures: '.$failures.'. Email failures are reported separately.');
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
