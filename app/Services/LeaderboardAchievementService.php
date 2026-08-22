<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\Student;
use App\Models\StudentAchievement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaderboardAchievementService
{
    private const LIMIT = 100;

    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    /**
     * Evaluate a global leaderboard snapshot for a period.
     *
     * The period is optional for all-time evaluation. Completed attempts are
     * ranked by the same tie-break order used by the existing leaderboard.
     */
    public function evaluate(
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        string $periodKey = 'all-time'
    ): array {
        $rankings = $this->rankings($startsAt, $endsAt);
        $awards = [];

        foreach ($rankings as $index => $ranking) {
            $rank = $index + 1;
            $code = $this->positionCode($rank);

            if (! $code) {
                continue;
            }

            $award = $this->award(
                $ranking->student_id,
                $code,
                $periodKey,
                $rank,
                $ranking
            );

            if ($award) {
                $awards[] = $award;
            }

            foreach ($this->championCodes($rank, $periodKey) as $championCode) {
                $award = $this->award(
                    $ranking->student_id,
                    $championCode,
                    $periodKey,
                    $rank,
                    $ranking
                );

                if ($award) {
                    $awards[] = $award;
                }
            }
        }

        return $awards;
    }

    private function rankings(
        ?CarbonInterface $startsAt,
        ?CarbonInterface $endsAt
    ): Collection {
        return Student::query()
            ->leftJoin('exam_attempts', function ($join) use ($startsAt, $endsAt) {
                $join->on('students.id', '=', 'exam_attempts.student_id')
                    ->where('exam_attempts.status', ExamAttempt::COMPLETED)
                    ->when($startsAt, fn ($query) => $query->where(
                        DB::raw('COALESCE(exam_attempts.submitted_at, exam_attempts.updated_at)'),
                        '>=',
                        $startsAt
                    ))
                    ->when($endsAt, fn ($query) => $query->where(
                        DB::raw('COALESCE(exam_attempts.submitted_at, exam_attempts.updated_at)'),
                        '<=',
                        $endsAt
                    ));
            })
            ->select('students.id as student_id')
            ->selectRaw('COUNT(exam_attempts.id) AS total_attempts')
            ->selectRaw('ROUND(AVG(exam_attempts.percentage), 2) AS average_score')
            ->selectRaw('MAX(exam_attempts.percentage) AS highest_score')
            ->selectRaw('COALESCE(SUM(exam_attempts.correct_answers), 0) AS total_correct_answers')
            ->selectRaw('COALESCE(SUM(exam_attempts.score), 0) AS total_score')
            ->groupBy('students.id')
            ->having('total_attempts', '>', 0)
            ->orderByDesc('total_correct_answers')
            ->orderByDesc('highest_score')
            ->orderByDesc('total_attempts')
            ->orderByDesc('average_score')
            ->limit(self::LIMIT)
            ->get();
    }

    private function positionCode(int $rank): ?string
    {
        return match (true) {
            $rank === 1 => 'leaderboard.number_one_student',
            $rank <= 3 => 'leaderboard.top_three',
            $rank <= 5 => 'leaderboard.top_five',
            $rank <= 10 => 'leaderboard.top_ten',
            $rank <= 25 => 'leaderboard.top_twenty_five',
            $rank <= 50 => 'leaderboard.top_fifty',
            $rank <= 100 => 'leaderboard.top_one_hundred',
            default => null,
        };
    }

    private function championCodes(int $rank, string $periodKey): array
    {
        return match (true) {
            $rank !== 1 => [],
            str_starts_with($periodKey, 'weekly-') => ['leaderboard.weekly_champion'],
            str_starts_with($periodKey, 'monthly-') => ['leaderboard.monthly_champion'],
            $periodKey === 'all-time' => ['leaderboard.all_time_champion'],
            default => [],
        };
    }

    private function award(
        int $studentId,
        string $code,
        string $periodKey,
        int $rank,
        object $ranking
    ): ?StudentAchievement {
        $student = Student::findOrFail($studentId);
        $award = $this->awardService->award(
            $student,
            $code,
            occurrenceKey: $periodKey,
            context: [
                'period_key' => $periodKey,
                'metadata' => [
                    'source' => 'global_leaderboard',
                    'rank' => $rank,
                    'total_attempts' => (int) $ranking->total_attempts,
                    'average_score' => (float) $ranking->average_score,
                    'total_correct_answers' => (int) $ranking->total_correct_answers,
                ],
            ]
        );

        $this->onboardingAchievementService
            ->firstQualifyingAchievementAwarded($award);

        return $award->wasRecentlyCreated ? $award : null;
    }
}
