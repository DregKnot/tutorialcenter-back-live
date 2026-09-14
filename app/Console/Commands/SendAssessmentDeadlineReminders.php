<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;

class SendAssessmentDeadlineReminders extends Command
{
    protected $signature = 'assessments:send-deadline-reminders';

    protected $description = 'Send due student reminders through in-app notifications and email';

    public function handle(): int
    {
        $failures = 0;
        Assessment::where('status', Assessment::PUBLISHED)->where('opens_at', '<=', now())->where('due_at', '>', now())->where('due_at', '<=', now()->addDay())
            ->chunkById(100, function ($records) use (&$failures) {
                foreach ($records as $record) {
                    try {
                        StudentNotificationService::assessmentReminder($record->id);
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
