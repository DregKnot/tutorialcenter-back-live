<?php

namespace App\Services\Admin;

use App\Models\ExamAttempt;
use App\Models\Student;
use App\Models\Subject;

class ExamAnalyticsService
{
    /**
     * Main analytics endpoint
     */
    public function overview(): array
    {
        return [
            'average_score' => $this->averageScore(),
            'pass_rate' => $this->passRate(),
            'completion_rate' => $this->completionRate(),
            'average_attempts_per_student' => $this->averageAttemptsPerStudent(),

            'students' => [
                'above_80' => $this->studentsAbove80(),
                'below_40' => $this->studentsBelow40(),
            ],

            'subjects' => [
                'most_attempted' => $this->mostAttemptedSubjects(),
                'least_attempted' => $this->leastAttemptedSubjects(),
                'highest_average' => $this->highestAverageSubjects(),
                'lowest_average' => $this->lowestAverageSubjects(),
            ],
        ];
    }

    /**
     * Average Percentage Score
     */
    private function averageScore(): float
    {
        return round(
            ExamAttempt::where(
                'status',
                ExamAttempt::COMPLETED
            )->avg('percentage') ?? 0,
            2
        );
    }

    /**
     * Pass Rate (50% pass mark)
     */
    private function passRate(): float
    {
        $total = ExamAttempt::where(
            'status',
            ExamAttempt::COMPLETED
        )->count();

        if ($total === 0) {
            return 0;
        }

        $passed = ExamAttempt::where(
            'status',
            ExamAttempt::COMPLETED
        )
            ->where('percentage', '>=', 50)
            ->count();

        return round(
            ($passed / $total) * 100,
            2
        );
    }

    /**
     * Completion Rate
     */
    private function completionRate(): float
    {
        $started = ExamAttempt::whereIn(
            'status',
            [
                ExamAttempt::COMPLETED,
                ExamAttempt::ABANDONED,
            ]
        )->count();

        if ($started === 0) {
            return 0;
        }

        $completed = ExamAttempt::where(
            'status',
            ExamAttempt::COMPLETED
        )->count();

        return round(
            ($completed / $started) * 100,
            2
        );
    }

    /**
     * Average Attempts Per Student
     */
    private function averageAttemptsPerStudent(): float
    {
        $students = Student::count();

        if ($students === 0) {
            return 0;
        }

        $attempts = ExamAttempt::where(
            'status',
            ExamAttempt::COMPLETED
        )->count();

        return round(
            $attempts / $students,
            2
        );
    }

    /**
     * Students Above 80%
     */
    private function studentsAbove80(): int
    {
        return ExamAttempt::where(
            'status',
            ExamAttempt::COMPLETED
        )
            ->where('percentage', '>=', 80)
            ->distinct('student_id')
            ->count('student_id');
    }

    /**
     * Students Below 40%
     */
    private function studentsBelow40(): int
    {
        return ExamAttempt::where(
            'status',
            ExamAttempt::COMPLETED
        )
            ->where('percentage', '<', 40)
            ->distinct('student_id')
            ->count('student_id');
    }

    /**
     * Most Attempted Subjects
     */
    private function mostAttemptedSubjects(int $limit = 5)
    {
        return Subject::query()
            ->join(
                'exam_years',
                'subjects.id',
                '=',
                'exam_years.subject_id'
            )
            ->join(
                'exam_attempts',
                'exam_years.id',
                '=',
                'exam_attempts.exam_year_id'
            )
            ->where(
                'exam_attempts.status',
                ExamAttempt::COMPLETED
            )
            ->select(
                'subjects.id',
                'subjects.name'
            )
            ->selectRaw(
                'COUNT(exam_attempts.id) as attempts'
            )
            ->groupBy(
                'subjects.id',
                'subjects.name'
            )
            ->orderByDesc('attempts')
            ->limit($limit)
            ->get();
    }

    /**
     * Least Attempted Subjects
     */
    private function leastAttemptedSubjects(int $limit = 5)
    {
        return Subject::query()
            ->join(
                'exam_years',
                'subjects.id',
                '=',
                'exam_years.subject_id'
            )
            ->join(
                'exam_attempts',
                'exam_years.id',
                '=',
                'exam_attempts.exam_year_id'
            )
            ->where(
                'exam_attempts.status',
                ExamAttempt::COMPLETED
            )
            ->select(
                'subjects.id',
                'subjects.name'
            )
            ->selectRaw(
                'COUNT(exam_attempts.id) as attempts'
            )
            ->groupBy(
                'subjects.id',
                'subjects.name'
            )
            ->orderBy('attempts')
            ->limit($limit)
            ->get();
    }

    /**
     * Highest Average Subjects
     */
    private function highestAverageSubjects(int $limit = 5)
    {
        return Subject::query()
            ->join(
                'exam_years',
                'subjects.id',
                '=',
                'exam_years.subject_id'
            )
            ->join(
                'exam_attempts',
                'exam_years.id',
                '=',
                'exam_attempts.exam_year_id'
            )
            ->where(
                'exam_attempts.status',
                ExamAttempt::COMPLETED
            )
            ->select(
                'subjects.id',
                'subjects.name'
            )
            ->selectRaw(
                'AVG(exam_attempts.percentage) as average_score'
            )
            ->groupBy(
                'subjects.id',
                'subjects.name'
            )
            ->orderByDesc('average_score')
            ->limit($limit)
            ->get();
    }

    /**
     * Lowest Average Subjects
     */
    private function lowestAverageSubjects(int $limit = 5)
    {
        return Subject::query()
            ->join(
                'exam_years',
                'subjects.id',
                '=',
                'exam_years.subject_id'
            )
            ->join(
                'exam_attempts',
                'exam_years.id',
                '=',
                'exam_attempts.exam_year_id'
            )
            ->where(
                'exam_attempts.status',
                ExamAttempt::COMPLETED
            )
            ->select(
                'subjects.id',
                'subjects.name'
            )
            ->selectRaw(
                'AVG(exam_attempts.percentage) as average_score'
            )
            ->groupBy(
                'subjects.id',
                'subjects.name'
            )
            ->orderBy('average_score')
            ->limit($limit)
            ->get();
    }

        /**
     * Leaderboard of students based on average score and total attempts
     */
    public function leaderboard(int $limit = 20)
    {
        $today = now()->toDateString();

        return Student::query()
            ->leftJoin(
                'exam_attempts',
                function ($join) {
                    $join->on(
                        'students.id',
                        '=',
                        'exam_attempts.student_id'
                    )
                        ->where(
                            'exam_attempts.status',
                            ExamAttempt::COMPLETED
                        );
                }
            )
            ->select(
                'students.id',
                'students.firstname',
                'students.surname',
                'students.profile_picture'
            )
            ->selectRaw("
            COUNT(exam_attempts.id) AS total_attempts,
            ROUND(AVG(exam_attempts.percentage),2) AS average_score,
            MAX(exam_attempts.percentage) AS highest_score,
            COALESCE(SUM(exam_attempts.correct_answers),0) AS total_correct_answers,
            COALESCE(SUM(exam_attempts.score),0) AS total_score,
            COALESCE(SUM(CASE WHEN DATE(exam_attempts.submitted_at) = ? THEN exam_attempts.score ELSE 0 END), 0) AS today_points
        ", [$today])
            ->groupBy(
                'students.id',
                'students.firstname',
                'students.surname',
                'students.profile_picture'
            )
            ->orderByDesc('total_correct_answers')
            ->orderByDesc('highest_score')
            ->orderByDesc('total_attempts')
            ->orderByDesc('average_score')
            ->limit($limit)
            ->get()
            ->values()
            ->map(function ($student, $index) {

                // Query dominant / most practiced subject
                $mostPracticed = ExamAttempt::query()
                    ->where('exam_attempts.student_id', $student->id)
                    ->where('exam_attempts.status', ExamAttempt::COMPLETED)
                    ->join('exam_years', 'exam_attempts.exam_year_id', '=', 'exam_years.id')
                    ->join('subjects', 'exam_years.subject_id', '=', 'subjects.id')
                    ->select('subjects.name')
                    ->selectRaw('COUNT(exam_attempts.id) as attempts_count')
                    ->groupBy('subjects.name')
                    ->orderByDesc('attempts_count')
                    ->value('subjects.name');

                return [
                    'rank' => $index + 1,
                    'student_id' => $student->id,
                    'name' => trim(
                        $student->firstname . ' ' . $student->surname
                    ),
                    'profile_picture' => $student->profile_picture,
                    'total_correct_answers' => (int) $student->total_correct_answers,
                    'total_attempts' => (int) $student->total_attempts,
                    'highest_score' => (int) $student->highest_score,
                    'average_score' => (float) $student->average_score,
                    'total_score' => (int) $student->total_score,
                    'today_points' => (int) $student->today_points,
                    'most_practiced_subject' => $mostPracticed ?: 'General Studies',
                ];
            });
    }

    /**
     * Deep leadership intelligence breakdown for a specific student
     */
    public function studentLeaderboardDetail(int $studentId): array
    {
        $student = Student::findOrFail($studentId);

        $attempts = ExamAttempt::query()
            ->where('student_id', $studentId)
            ->where('exam_attempts.status', ExamAttempt::COMPLETED)
            ->with(['examYear.subject'])
            ->orderByDesc('submitted_at')
            ->get();

        $today = now()->toDateString();
        $totalPoints = (int) $attempts->sum('score');
        $totalAttempts = (int) $attempts->count();
        $totalCorrect = (int) $attempts->sum('correct_answers');
        $totalQuestions = (int) $attempts->sum('total_questions');
        $avgScore = $totalAttempts > 0 ? (float) round($attempts->avg('percentage'), 2) : 0.0;
        $highestScore = (int) ($attempts->max('percentage') ?? 0);

        // Group by Subject to calculate subject dominance, correct answers, and points
        $subjectBreakdowns = $attempts->groupBy(function ($attempt) {
            return $attempt->examYear?->subject?->id ?? 0;
        })->map(function ($subjectAttempts, $subjectId) use ($today, $student) {
            $first = $subjectAttempts->first();
            $subjectName = $first->examYear?->subject?->name ?? 'General Studies';

            $todayAttempts = $subjectAttempts->filter(function ($a) use ($today) {
                return $a->submitted_at && $a->submitted_at->toDateString() === $today;
            });

            $totalCorrect = (int) $subjectAttempts->sum('correct_answers');
            $totalQuestionsAttempted = (int) $subjectAttempts->sum('total_questions');
            $accuracy = $totalQuestionsAttempted > 0 ? round(($totalCorrect / $totalQuestionsAttempted) * 100, 1) : 0;

            $totalInBank = 0;
            $uniqueAnswered = 0;
            $bankCoverage = 0;

            if ($subjectId > 0) {
                $totalInBank = \App\Models\PastQuestion::whereHas('examYear', function ($q) use ($subjectId) {
                    $q->where('subject_id', $subjectId)->whereNull('deleted_at');
                })->whereNull('deleted_at')->count();

                $uniqueAnswered = \DB::table('exam_attempt_answers')
                    ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_answers.exam_attempt_id')
                    ->join('exam_years', 'exam_years.id', '=', 'exam_attempts.exam_year_id')
                    ->where('exam_attempts.student_id', $student->id)
                    ->where('exam_years.subject_id', $subjectId)
                    ->distinct('exam_attempt_answers.past_question_id')
                    ->count('exam_attempt_answers.past_question_id');

                $bankCoverage = $totalInBank > 0 ? round(($uniqueAnswered / $totalInBank) * 100, 1) : 0;
            }

            return [
                'subject_id' => $subjectId,
                'subject' => $subjectName,
                'accumulated_score' => (int) $subjectAttempts->sum('score'),
                'total_attempts' => (int) $subjectAttempts->count(),
                'total_correct' => $totalCorrect,
                'total_questions' => $totalQuestionsAttempted,
                'accuracy_percentage' => $accuracy,
                'unique_questions_answered' => $uniqueAnswered,
                'total_questions_in_bank' => $totalInBank,
                'bank_coverage_percentage' => $bankCoverage,
                'highest_score' => (int) ($subjectAttempts->max('percentage') ?? 0),
                'avg_score' => (int) round($subjectAttempts->avg('percentage') ?? 0),
                'today_score' => (int) $todayAttempts->sum('score'),
                'today_attempts' => (int) $todayAttempts->count(),
            ];
        })->sortByDesc('total_attempts')->values()->all();

        $mostPracticed = !empty($subjectBreakdowns) ? $subjectBreakdowns[0]['subject'] : 'General Studies';

        // Group by Date for the Daily Points Timeline
        $dailyTimeline = $attempts->filter(fn($a) => $a->submitted_at !== null)
            ->groupBy(function ($attempt) {
                return $attempt->submitted_at->toDateString();
            })->map(function ($dateAttempts, $dateStr) {
                $subjects = $dateAttempts->map(function ($a) {
                    return $a->examYear?->subject?->name ?? 'General';
                })->unique()->values()->all();

                return [
                    'date' => $dateStr,
                    'points_accumulated' => (int) $dateAttempts->sum('score'),
                    'attempts_count' => (int) $dateAttempts->count(),
                    'subjects' => $subjects,
                ];
            })->sortByDesc('date')->values()->all();

        // Today's points
        $todayAttempts = $attempts->filter(fn($a) => $a->submitted_at && $a->submitted_at->toDateString() === $today);
        $todayPoints = (int) $todayAttempts->sum('score');

        return [
            'student_id' => $student->id,
            'name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')) ?: $student->name,
            'profile_picture' => $student->profile_picture,
            'total_score' => $totalPoints,
            'total_attempts' => $totalAttempts,
            'total_correct_answers' => $totalCorrect,
            'total_questions' => $totalQuestions,
            'average_score' => $avgScore,
            'highest_score' => $highestScore,
            'today_points' => $todayPoints,
            'most_practiced_subject' => $mostPracticed,
            'subject_breakdowns' => $subjectBreakdowns,
            'daily_timeline' => $dailyTimeline,
        ];
    }
    
    // public function leaderboard(int $limit = 20)
    // {
    //     return Student::query()
    //         ->leftJoin(
    //             'exam_attempts',
    //             function ($join) {
    //                 $join->on(
    //                     'students.id',
    //                     '=',
    //                     'exam_attempts.student_id'
    //                 )
    //                     ->where(
    //                         'exam_attempts.status',
    //                         ExamAttempt::COMPLETED
    //                     );
    //             }
    //         )
    //         ->select(
    //             'students.id',
    //             'students.firstname',
    //             'students.surname',
    //             'students.profile_picture'
    //         )
    //         ->selectRaw("
    //         COUNT(exam_attempts.id) AS total_attempts,
    //         ROUND(AVG(exam_attempts.percentage),2) AS average_score,
    //         MAX(exam_attempts.percentage) AS highest_score,
    //         SUM(exam_attempts.correct_answers) AS total_correct_answers,
    //         SUM(exam_attempts.score) AS total_score
    //     ")
    //         ->groupBy(
    //             'students.id',
    //             'students.firstname',
    //             'students.surname',
    //             'students.profile_picture'
    //         )
    //         ->orderByDesc('average_score')
    //         ->orderByDesc('total_attempts')
    //         ->limit($limit)
    //         ->get()
    //         ->values()
    //         ->map(function ($student, $index) {

    //             return [

    //                 'rank' => $index + 1,

    //                 'student_id' => $student->id,

    //                 'name' =>
    //                 trim(
    //                     $student->firstname .
    //                         ' ' .
    //                         $student->surname
    //                 ),

    //                 'profile_picture' =>
    //                 $student->profile_picture,

    //                 'average_score' =>
    //                 (float) ($student->average_score ?? 0),

    //                 'highest_score' =>
    //                 (int) ($student->highest_score ?? 0),

    //                 'total_attempts' =>
    //                 (int) $student->total_attempts,

    //                 'total_correct_answers' =>
    //                 (int) ($student->total_correct_answers ?? 0),

    //                 'total_score' =>
    //                 (int) ($student->total_score ?? 0),
    //             ];
    //         });
    // }
}
