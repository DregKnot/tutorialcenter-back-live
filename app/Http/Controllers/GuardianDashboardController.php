<?php

namespace App\Http\Controllers;

use App\Models\ClassAttendance;
use App\Models\ExamAttempt;
use Illuminate\Http\Request;

class GuardianDashboardController extends Controller
{
    /**
     * Return the authenticated guardian's wards and their active courses.
     */
    public function getWardsDashboard(Request $request)
    {
        $students = $request->user()
            ->students()
            ->select([
                'students.id',
                'students.firstname',
                'students.surname',
                'students.email',
<<<<<<< HEAD
=======
                'students.department',
>>>>>>> 9c879873ce75ee779f616888dc10df030d19121f
                'students.updated_at',
            ])
            ->with([
                'courseEnrollments' => function ($query) {
                    $query
                        ->select([
                            'id',
                            'student_id',
                            'course_id',
                        ])
                        ->where('status', 'active')
                        ->where('start_date', '<=', now())
                        ->where(function ($query) {
                            $query
                                ->whereNull('end_date')
                                ->orWhere('end_date', '>=', now());
                        })
                        ->whereHas('payments', function ($query) {
                            $query->where('status', 'successful');
                        })
                        ->with('course:id,title');
                },
<<<<<<< HEAD
=======
                'subjectEnrollments.subject:id,name'
>>>>>>> 9c879873ce75ee779f616888dc10df030d19121f
            ])
            ->get();

        $dashboardData = $students->map(function ($student) {
            return [
                'id' => $student->id,
                'name' => trim($student->firstname . ' ' . $student->surname),
                'email' => $student->email,
<<<<<<< HEAD
=======
                'department' => $student->department,
>>>>>>> 9c879873ce75ee779f616888dc10df030d19121f
                'active_courses' => $student->courseEnrollments
                    ->pluck('course')
                    ->filter()
                    ->values(),
<<<<<<< HEAD
=======
                'subjects' => $student->subjectEnrollments
                    ->pluck('subject')
                    ->filter()
                    ->values(),
>>>>>>> 9c879873ce75ee779f616888dc10df030d19121f
                'last_active' => $student->updated_at,
            ];
        });

        return response()->json([
            'data' => $dashboardData,
        ]);
    }

    /**
     * Return exam-practice performance for one of the guardian's wards.
     */
    public function getWardPerformance(Request $request, int $student_id)
    {
        $isWard = $request->user()
            ->students()
            ->where('students.id', $student_id)
            ->exists();

        if (!$isWard) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $performanceData = ExamAttempt::query()
            ->join('exam_years', 'exam_years.id', '=', 'exam_attempts.exam_year_id')
            ->join('subjects', 'subjects.id', '=', 'exam_years.subject_id')
            ->where('exam_attempts.student_id', $student_id)
            ->where('exam_attempts.status', ExamAttempt::COMPLETED)
            ->whereNull('exam_years.deleted_at')
            ->whereNull('subjects.deleted_at')
            ->groupBy('subjects.id', 'subjects.name')
            ->orderBy('subjects.name')
            ->select([
                'subjects.id as subject_id',
                'subjects.name as subject_name',
            ])
            ->selectRaw('ROUND(AVG(exam_attempts.percentage), 2) as average_score')
            ->selectRaw('MAX(exam_attempts.percentage) as highest_score')
            ->selectRaw('MIN(exam_attempts.percentage) as lowest_score')
            ->selectRaw('COUNT(exam_attempts.id) as total_practices')
            ->get();

        return response()->json([
            'merits_by_subject' => $performanceData,
        ]);
    }

    /**
     * Return the 10 most recent live-class attendance records for a ward.
     */
    public function getWardAttendance(Request $request, int $student_id)
    {
        $isWard = $request->user()
            ->students()
            ->where('students.id', $student_id)
            ->exists();

        if (!$isWard) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $attendances = ClassAttendance::query()
            ->where('student_id', $student_id)
            ->select([
                'id',
                'class_session_id',
                'student_id',
                'joined_at',
                'left_at',
                'attendance_duration',
                'status',
            ])
            ->with([
                'session:id,class_id,session_date,starts_at,ends_at',
                'session.class:id,title',
            ])
            ->orderByDesc('joined_at')
            ->limit(10)
            ->get();

        return response()->json([
            'attendance' => $attendances,
        ]);
    }
<<<<<<< HEAD
=======

    /**
     * Return today's exam-practice performance overview for one of the guardian's wards.
     */
    public function getWardPerformanceOverview(Request $request, int $student_id)
    {
        $isWard = $request->user()
            ->students()
            ->where('students.id', $student_id)
            ->exists();

        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $todayAttempts = ExamAttempt::query()
            ->join('exam_years', 'exam_years.id', '=', 'exam_attempts.exam_year_id')
            ->join('subjects', 'subjects.id', '=', 'exam_years.subject_id')
            ->where('exam_attempts.student_id', $student_id)
            ->where('exam_attempts.status', ExamAttempt::COMPLETED)
            ->whereDate('exam_attempts.created_at', now()->toDateString())
            ->whereNull('exam_years.deleted_at')
            ->whereNull('subjects.deleted_at')
            ->select([
                'exam_attempts.id',
                'exam_attempts.percentage',
                'subjects.id as subject_id',
                'subjects.name as subject_name',
            ])
            ->get();

        $subjectsPracticedCount = $todayAttempts->pluck('subject_id')->unique()->count();
        $totalAttempts = $todayAttempts->count();
        $averageScore = $totalAttempts > 0 ? round($todayAttempts->avg('percentage'), 2) : 0;
        
        $mostPracticedSubject = null;
        if ($totalAttempts > 0) {
            $groupedBySubject = $todayAttempts->groupBy('subject_name');
            $mostPracticedSubject = $groupedBySubject->sortByDesc(function ($attempts) {
                return $attempts->count();
            })->keys()->first();
        }

        return response()->json([
            'subjects_practiced_today' => $subjectsPracticedCount,
            'most_practiced_subject' => $mostPracticedSubject,
            'total_attempts_today' => $totalAttempts,
            'average_score_today' => $averageScore,
        ]);
    }

    /**
     * Return the active subscription duration and price for a ward.
     */
    public function getWardSubscription(Request $request, int $student_id)
    {
        $isWard = $request->user()
            ->students()
            ->where('students.id', $student_id)
            ->exists();

        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activeSubscription = \App\Models\CoursesEnrollment::query()
            ->where('student_id', $student_id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->whereHas('payments', function ($query) {
                $query->where('status', 'successful');
            })
            ->latest('start_date')
            ->first();

        if (!$activeSubscription) {
            return response()->json([
                'has_active_subscription' => false,
            ]);
        }

        $daysLeft = $activeSubscription->end_date ? (int) now()->diffInDays($activeSubscription->end_date, false) : null;
        
        return response()->json([
            'has_active_subscription' => true,
            'end_date' => $activeSubscription->end_date,
            'days_left' => $daysLeft > 0 ? $daysLeft : 0,
            'cost' => $activeSubscription->cost,
            'billing_cycle' => $activeSubscription->billing_cycle,
        ]);
    }

    /**
     * Return a dynamically calculated weekly report for a ward.
     */
    public function getWardWeeklyReport(Request $request, int $student_id)
    {
        $isWard = $request->user()
            ->students()
            ->where('students.id', $student_id)
            ->exists();

        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        $currentWeekAttempts = ExamAttempt::query()
            ->where('student_id', $student_id)
            ->where('status', ExamAttempt::COMPLETED)
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->get();

        if ($currentWeekAttempts->isEmpty()) {
            // Check previous week if current week is empty
            $startOfWeek = now()->subWeek()->startOfWeek();
            $endOfWeek = now()->subWeek()->endOfWeek();

            $currentWeekAttempts = ExamAttempt::query()
                ->where('student_id', $student_id)
                ->where('status', ExamAttempt::COMPLETED)
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->get();
        }

        $totalAttempts = $currentWeekAttempts->count();
        $averageScore = $totalAttempts > 0 ? round($currentWeekAttempts->avg('percentage'), 2) : 0;
        
        $reportSummary = "Your ward has completed {$totalAttempts} practices this week with an average score of {$averageScore}%.";
        if ($totalAttempts === 0) {
            $reportSummary = "Your ward has not completed any practices recently.";
        }

        return response()->json([
            'report_period' => [
                'start' => $startOfWeek->toDateString(),
                'end' => $endOfWeek->toDateString(),
            ],
            'total_attempts' => $totalAttempts,
            'average_score' => $averageScore,
            'summary' => $reportSummary,
        ]);
    }
>>>>>>> 9c879873ce75ee779f616888dc10df030d19121f
}
