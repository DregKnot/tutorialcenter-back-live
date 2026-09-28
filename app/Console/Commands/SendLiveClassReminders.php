<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Services\StudentNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendLiveClassReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'classes:send-live-reminders {--session= : Specific ClassSession ID to send reminder for (bypasses 15m time window)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send 15-minute live class start reminders to enrolled students, guardians, and advisors';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $failures = 0;
        $tz = config('app.timezone', 'Africa/Lagos');
        $now = now()->timezone($tz);

        // If specific session requested (e.g. for testing)
        $targetSessionId = $this->option('session');
        if ($targetSessionId) {
            $session = ClassSession::with(['class.subject'])->find($targetSessionId);
            if (! $session) {
                $this->error("Class session #{$targetSessionId} not found.");
                return self::FAILURE;
            }

            $this->info("Sending live class reminder for session #{$session->id}...");
            try {
                StudentNotificationService::sendLiveClassReminder($session);
                $this->info("Live class reminder sent for session #{$session->id}.");
                return self::SUCCESS;
            } catch (\Throwable $exception) {
                $this->error("Failed sending reminder: " . $exception->getMessage());
                Log::error('Manual live class reminder failed', [
                    'session_id' => $session->id,
                    'message' => $exception->getMessage(),
                ]);
                report($exception);
                return self::FAILURE;
            }
        }

        // Look for scheduled sessions around today's date boundary
        $yesterday = $now->copy()->subDay()->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();

        ClassSession::with(['class.subject'])
            ->where('status', 'scheduled')
            ->whereBetween('session_date', [$yesterday, $tomorrow])
            ->whereNotNull('starts_at')
            ->chunkById(100, function ($sessions) use ($now, &$failures) {
                foreach ($sessions as $session) {
                    try {
                        $startTime = StudentNotificationService::sessionTime($session, 'starts_at');
                        if (! $startTime) {
                            continue;
                        }

                        // Calculate difference in seconds: positive if session starts in the future
                        $diffSeconds = $now->diffInSeconds($startTime, false);

                        // If class starts within the next 15 minutes (0 < diffSeconds <= 900)
                        if ($diffSeconds > 0 && $diffSeconds <= 900) {
                            StudentNotificationService::sendLiveClassReminder($session);
                        }
                    } catch (\Throwable $exception) {
                        $failures++;
                        Log::error('Live class reminder failed', [
                            'session_id' => $session->id,
                            'message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ]);
                        report($exception);
                    }
                }
            });

        $this->info('Live class reminder check completed. Processing failures: ' . $failures . '.');
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}

