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
            ])
            ->get();

        $dashboardData = $students->map(function ($student) {
            return [
                'id' => $student->id,
                'name' => trim($student->firstname . ' ' . $student->surname),
                'email' => $student->email,
                'active_courses' => $student->courseEnrollments
                    ->pluck('course')
                    ->filter()
                    ->values(),
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
}
