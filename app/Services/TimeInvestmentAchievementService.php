<?php

namespace App\Services;

use App\Models\ExamAttemptActivitySession;
use App\Models\Student;
use App\Models\StudentAchievementProgress;
use Illuminate\Support\Facades\DB;
use Throwable;

class TimeInvestmentAchievementService
{
    private const PROGRESS_KEY = 'lifetime_active_exam_seconds';

    private const MILESTONES = [
        3600 => 'time_investment.one_hour_learner',
        36000 => 'time_investment.ten_hours_scholar',
        90000 => 'time_investment.twenty_five_hours_learner',
        180000 => 'time_investment.fifty_hours_scholar',
        360000 => 'time_investment.one_hundred_hour_academic_hero',
        900000 => 'time_investment.two_hundred_fifty_hours_study_master',
        1800000 => 'time_investment.five_hundred_hours_education_legend',
    ];

    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    public function evaluate(Student $student): array
    {
        $progress = DB::transaction(function () use ($student) {
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $activeSeconds = (int) ExamAttemptActivitySession::where(
                'student_id',
                $student->id
            )->sum('active_seconds');

            $progress = StudentAchievementProgress::where('student_id', $student->id)
                ->where('progress_key', self::PROGRESS_KEY)
                ->whereNull('subject_id')
                ->lockForUpdate()
                ->first();

            if (! $progress) {
                $progress = StudentAchievementProgress::create([
                    'student_id' => $student->id,
                    'progress_key' => self::PROGRESS_KEY,
                    'duration_seconds' => $activeSeconds,
                    'integer_value' => $activeSeconds,
                    'calculated_at' => now(),
                ]);
            } else {
                $progress->update([
                    'duration_seconds' => $activeSeconds,
                    'integer_value' => $activeSeconds,
                    'calculated_at' => now(),
                ]);
            }

            return $progress->refresh();
        });

        $awards = [];
        $activeSeconds = (int) $progress->duration_seconds;

        foreach (self::MILESTONES as $threshold => $code) {
            if ($activeSeconds < $threshold) {
                continue;
            }

            try {
                $award = $this->awardService->award(
                    $student,
                    $code,
                    context: [
                        'metadata' => [
                            'source' => 'lifetime_exam_practice_time',
                            'active_seconds' => $activeSeconds,
                            'threshold_seconds' => $threshold,
                        ],
                    ]
                );

                $this->onboardingAchievementService
                    ->firstQualifyingAchievementAwarded($award);

                if ($award->wasRecentlyCreated) {
                    $awards[] = $award;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return [
            'progress' => $progress,
            'awards' => $awards,
        ];
    }
}
