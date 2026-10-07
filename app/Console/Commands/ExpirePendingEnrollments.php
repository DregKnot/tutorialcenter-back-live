<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CoursesEnrollment;

class ExpirePendingEnrollments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enrollments:expire-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire pending course enrollments older than 48 hours without payment';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoff = now()->subHours(48);

        $expired = CoursesEnrollment::where('status', 'pending')
            ->where(function ($q) use ($cutoff) {
                $q->where('expires_at', '<=', now())
                  ->orWhere(function ($q2) use ($cutoff) {
                      $q2->whereNull('expires_at')->where('created_at', '<=', $cutoff);
                  });
            })
            ->get();

        $count = 0;
        foreach ($expired as $enrollment) {
            $enrollment->update([
                'status' => 'expired',
                'termination_reason' => 'Automatically expired after 48 hours without payment confirmation.',
            ]);

            $enrollment->payments()->where('status', 'pending')->update([
                'status' => 'failed',
            ]);

            $count++;
        }

        $this->info("Expired {$count} pending course enrollments.");
        return 0;
    }
}
