<?php

namespace App\Console\Commands;

use App\Services\LeaderboardAchievementService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Throwable;

class EvaluateLeaderboardAchievements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'achievements:evaluate-leaderboard
                            {--date= : Date used to determine the completed periods}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Award weekly, monthly, and all-time leaderboard achievements';

    /**
     * Execute the console command.
     */
    public function handle(LeaderboardAchievementService $leaderboardService): int
    {
        try {
            $asOf = $this->option('date')
                ? Carbon::parse((string) $this->option('date'), 'Africa/Lagos')
                : now('Africa/Lagos');
        } catch (Throwable) {
            $this->error('The supplied date is invalid.');

            return self::FAILURE;
        }

        $previousWeek = $asOf->copy()->subWeek();
        $weekStart = $previousWeek->copy()->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $previousWeek->copy()->endOfWeek(CarbonInterface::SUNDAY);
        $previousMonth = $asOf->copy()->subMonthNoOverflow();
        $monthStart = $previousMonth->copy()->startOfMonth();
        $monthEnd = $previousMonth->copy()->endOfMonth();

        $weeklyAwards = $leaderboardService->evaluate(
            $weekStart->copy()->utc(),
            $weekEnd->copy()->utc(),
            'weekly-'.$previousWeek->format('o-\\WW')
        );
        $monthlyAwards = $leaderboardService->evaluate(
            $monthStart->copy()->utc(),
            $monthEnd->copy()->utc(),
            'monthly-'.$previousMonth->format('Y-m')
        );
        $allTimeAwards = $leaderboardService->evaluate(
            periodKey: 'all-time'
        );
        $totalAwards = count($weeklyAwards)
            + count($monthlyAwards)
            + count($allTimeAwards);

        $this->info('Leaderboard achievement evaluation completed.');
        $this->table(
            ['Weekly awards', 'Monthly awards', 'All-time awards', 'Total new awards'],
            [[
                count($weeklyAwards),
                count($monthlyAwards),
                count($allTimeAwards),
                $totalAwards,
            ]]
        );

        return self::SUCCESS;
    }
}
