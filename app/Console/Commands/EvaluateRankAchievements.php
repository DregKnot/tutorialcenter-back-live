<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\RankAchievementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class EvaluateRankAchievements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'achievements:evaluate-ranks
                            {--date= : Evaluate ranks as of this date; defaults to now}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Award subscription-duration rank medals to eligible students';

    /**
     * Execute the console command.
     */
    public function handle(RankAchievementService $rankService): int
    {
        try {
            $asOf = $this->option('date')
                ? Carbon::parse((string) $this->option('date'))->endOfDay()
                : now();
        } catch (Throwable) {
            $this->error('The supplied date is invalid.');

            return self::FAILURE;
        }

        $processed = 0;
        $awarded = 0;
        $skipped = 0;
        $failed = 0;

        Student::query()
            ->whereHas('courseEnrollments.payments', function ($query) {
                $query->where('status', 'successful');
            })
            ->chunkById(100, function ($students) use (
                $rankService,
                $asOf,
                &$processed,
                &$awarded,
                &$skipped,
                &$failed
            ) {
                foreach ($students as $student) {
                    try {
                        $award = $rankService->evaluate($student, $asOf);
                        $processed++;

                        if ($award?->wasRecentlyCreated) {
                            $awarded++;
                        } else {
                            $skipped++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            });

        $this->info('Rank achievement evaluation completed.');
        $this->table(
            ['Processed', 'New awards', 'Skipped', 'Failed'],
            [[$processed, $awarded, $skipped, $failed]]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
