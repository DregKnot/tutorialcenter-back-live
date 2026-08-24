<?php

namespace App\Console\Commands;

use App\Models\SpecialEventCalendar;
use App\Models\Student;
use App\Services\SpecialEventAchievementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class EvaluateSpecialEventAchievements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'achievements:evaluate-special-events
                            {--country= : Evaluate only one ISO country code}
                            {--date= : Evaluate events ended by this date; defaults to now}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Award country-specific special-event achievements';

    /**
     * Execute the console command.
     */
    public function handle(
        SpecialEventAchievementService $achievementService
    ): int {
        try {
            $asOf = $this->option('date')
                ? Carbon::parse((string) $this->option('date'))->utc()
                : now()->utc();
        } catch (Throwable) {
            $this->error('The supplied date is invalid.');

            return self::FAILURE;
        }

        $countryOption = $this->option('country');
        $countryCodes = $countryOption
            ? collect([strtoupper((string) $countryOption)])
            : SpecialEventCalendar::query()
                ->active()
                ->endedBy($asOf)
                ->distinct()
                ->pluck('country_code');

        $processed = 0;
        $awarded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($countryCodes as $countryCode) {
            $countryNames = $this->countryNames($countryCode);

            if ($countryNames === []) {
                $this->warn("No student-location mapping exists for {$countryCode}.");

                continue;
            }

            Student::query()
                ->where(function ($query) use ($countryNames) {
                    foreach ($countryNames as $countryName) {
                        $query->orWhere('location', 'like', "%{$countryName}%");
                    }
                })
                ->chunkById(100, function ($students) use (
                    $achievementService,
                    $countryCode,
                    $asOf,
                    &$processed,
                    &$awarded,
                    &$skipped,
                    &$failed
                ) {
                    foreach ($students as $student) {
                        try {
                            $awards = $achievementService->evaluateCountryEvents(
                                $student,
                                $countryCode,
                                $asOf
                            );
                            $processed++;
                            $awarded += count($awards);

                            if ($awards === []) {
                                $skipped++;
                            }
                        } catch (Throwable $exception) {
                            report($exception);
                            $failed++;
                        }
                    }
                });
        }

        $this->info('Special-event achievement evaluation completed.');
        $this->table(
            ['Processed', 'New awards', 'Skipped', 'Failed'],
            [[$processed, $awarded, $skipped, $failed]]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function countryNames(string $countryCode): array
    {
        return match (strtoupper($countryCode)) {
            'NG' => ['Nigeria'],
            'ZA' => ['South Africa'],
            default => [],
        };
    }
}
