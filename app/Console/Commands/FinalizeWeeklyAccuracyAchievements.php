<?php

namespace App\Console\Commands;

use App\Models\ExamAttempt;
use App\Models\Student;
use App\Services\ImprovementAchievementService;
use App\Services\WeeklyAccuracyAchievementService;
use App\Services\WeeklyPerformanceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinalizeWeeklyAccuracyAchievements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'achievements:finalize-weekly-accuracy
                            {--timezone=Africa/Lagos : Timezone used for week boundaries}
                            {--date= : Any date within the completed week; defaults to the previous week}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finalize weekly performance and award weekly accuracy medals';

    /**
     * Execute the console command.
     */
    public function handle(
        WeeklyPerformanceService $performanceService,
        WeeklyAccuracyAchievementService $achievementService,
        ImprovementAchievementService $improvementAchievementService
    ): int {
        $timezone = (string) $this->option('timezone');

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->error('The supplied timezone is invalid.');

            return self::FAILURE;
        }

        try {
            $targetDate = $this->option('date')
                ? Carbon::parse((string) $this->option('date'), $timezone)
                : now($timezone)->subWeek();
        } catch (Throwable) {
            $this->error('The supplied date is invalid.');

            return self::FAILURE;
        }

        $weekStart = $targetDate->copy()
            ->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $targetDate->copy()
            ->endOfWeek(CarbonInterface::SUNDAY);

        if ($weekEnd->isFuture()) {
            $this->error('The selected week has not ended yet.');

            return self::FAILURE;
        }

        $queryStart = $weekStart->copy()->utc();
        $queryEnd = $weekEnd->copy()->utc();
        $weekKey = $targetDate->format('o-\WW');
        $processed = 0;
        $awarded = 0;
        $skipped = 0;
        $failed = 0;

        Student::query()
            ->whereHas('examAttempts', function ($query) use (
                $queryStart,
                $queryEnd
            ) {
                $query->whereIn('status', [
                    ExamAttempt::COMPLETED,
                    ExamAttempt::ABANDONED,
                ])->whereBetween(
                    DB::raw('COALESCE(submitted_at, updated_at)'),
                    [$queryStart, $queryEnd]
                );
            })
            ->chunkById(100, function ($students) use (
                $performanceService,
                $achievementService,
                $improvementAchievementService,
                $targetDate,
                $timezone,
                &$processed,
                &$awarded,
                &$skipped,
                &$failed
            ) {
                foreach ($students as $student) {
                    try {
                        $performance = $performanceService->calculate(
                            $student,
                            $targetDate,
                            $timezone,
                            true
                        );

                        $award = $achievementService->award($performance);
                        $improvementAwards = $improvementAchievementService->evaluate($student);
                        $processed++;

                        $newAwardCount = ($award?->wasRecentlyCreated ? 1 : 0)
                            + count($improvementAwards);

                        $awarded += $newAwardCount;

                        if ($newAwardCount === 0) {
                            $skipped++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            });

        $this->info("Weekly accuracy processing completed for {$weekKey}.");
        $this->table(
            ['Processed', 'New awards', 'Skipped', 'Failed'],
            [[$processed, $awarded, $skipped, $failed]]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
