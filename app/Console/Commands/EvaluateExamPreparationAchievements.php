<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\ExamPreparationAchievementService;
use Illuminate\Console\Command;
use Throwable;

class EvaluateExamPreparationAchievements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'achievements:evaluate-exam-preparation
                            {--student= : Process successful payments for one student ID}
                            {--payment= : Process one successful payment ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill exam-preparation badges from successful payments';

    /**
     * Execute the console command.
     */
    public function handle(
        ExamPreparationAchievementService $achievementService
    ): int {
        $query = Payment::query()
            ->where('status', 'successful')
            ->with(['student', 'enrollment']);

        if ($studentId = $this->option('student')) {
            if (! ctype_digit((string) $studentId)) {
                $this->error('The student option must be a numeric ID.');

                return self::FAILURE;
            }

            $query->where('student_id', (int) $studentId);
        }

        if ($paymentId = $this->option('payment')) {
            if (! ctype_digit((string) $paymentId)) {
                $this->error('The payment option must be a numeric ID.');

                return self::FAILURE;
            }

            $query->whereKey((int) $paymentId);
        }

        $processed = 0;
        $awarded = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function ($payments) use (
            $achievementService,
            &$processed,
            &$awarded,
            &$skipped,
            &$failed
        ) {
            foreach ($payments as $payment) {
                try {
                    $awards = $achievementService->evaluatePayment($payment);
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

        $this->info('Exam-preparation achievement evaluation completed.');
        $this->table(
            ['Processed', 'New awards', 'Skipped', 'Failed'],
            [[$processed, $awarded, $skipped, $failed]]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
