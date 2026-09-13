<?php

namespace App\Console\Commands;

use App\Models\ClassAttendance;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkAbandonedClassAttendance extends Command
{
    protected $signature = 'attendance:mark-abandoned';

    protected $description = 'Close ended classes and notify assigned recipients of disconnected attendance';

    public function handle(): int
    {
        if (! StudentNotificationService::enabled()) {
            $this->info('Student activity notifications are disabled.');

            return self::SUCCESS;
        }

        ClassAttendance::where('connection_state', 'active')
            ->whereNotNull('last_seen_at')
            ->chunkById(100, function ($attendances) {
                foreach ($attendances as $attendance) {
                    DB::transaction(function () use ($attendance) {
                        $locked = ClassAttendance::whereKey($attendance->id)->lockForUpdate()->first();
                        $locked?->closeStaleVisit();
                    });
                }
            });

        $this->info('Class attendance checked.');

        return self::SUCCESS;
    }
}
