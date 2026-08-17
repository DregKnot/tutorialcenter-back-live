<?php

namespace App\Console\Commands;

use App\Models\ExamAttempt;
use App\Models\StudentSubjectTrial;
use App\Services\ExamActivityService;
use App\Services\SubjectTrialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkAbandonedExams extends Command
{
    protected $signature =
        'exam:mark-abandoned';

    protected $description =
        'Mark stale exam attempts abandoned';

    public function handle(
        SubjectTrialService $subjectTrialService,
        ExamActivityService $examActivityService
    ) {
        ExamAttempt::where(
            'status',
            ExamAttempt::IN_PROGRESS
        )
            ->where(
                'started_at',
                '<',
                now()->subHours(2)
            )
            ->chunkById(100, function ($attempts) use (
                $subjectTrialService,
                $examActivityService
            ) {
                foreach ($attempts as $attempt) {
                    DB::transaction(function () use (
                        $attempt,
                        $subjectTrialService,
                        $examActivityService
                    ) {
                        $lockedAttempt = ExamAttempt::lockForUpdate()
                            ->find($attempt->id);

                        if (
                            ! $lockedAttempt ||
                            $lockedAttempt->status !== ExamAttempt::IN_PROGRESS ||
                            $lockedAttempt->started_at >= now()->subHours(2)
                        ) {
                            return;
                        }

                        $lockedAttempt->update([
                            'status' => ExamAttempt::ABANDONED,
                        ]);

                        $subjectTrialService->recordEnded(
                            $lockedAttempt,
                            StudentSubjectTrial::ABANDONED
                        );

                        $examActivityService->endOpenSessionsForAttempt(
                            $lockedAttempt,
                            'abandoned'
                        );
                    });
                }
            });

        $this->info(
            'Expired attempts updated'
        );
    }
}
