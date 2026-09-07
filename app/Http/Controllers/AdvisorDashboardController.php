<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExamAttempt;
use App\Models\Student;
use App\Models\Guardian;

class AdvisorDashboardController extends Controller
{
    /**
     * Get dashboard statistics (Average attempts and points)
     */
    public function stats(Request $request)
    {
        // Calculate average attempts per student
        $totalAttempts = ExamAttempt::count();
        $totalStudents = Student::count();
        
        $averageAttemptsPerStudent = $totalStudents > 0 ? round($totalAttempts / $totalStudents, 2) : 0;
        
        // Calculate average point across all exams
        $averagePointPerExam = ExamAttempt::avg('percentage') ?? 0;
        
        return response()->json([
            'average_attempts_per_student' => $averageAttemptsPerStudent,
            'average_point_per_exam' => round($averagePointPerExam, 2),
        ], 200);
    }

    /**
     * Get all guardians and their wards
     */
    public function guardians(Request $request)
    {
        // Fetch guardians with their associated students and academic enrollments
        $guardians = Guardian::with([
            'students.courseEnrollments.course',
            'students.courseEnrollments.subjects.subject',
        ])
        ->orderBy('created_at', 'desc')
        ->get();
        
        return response()->json([
            'message' => 'Guardians retrieved successfully',
            'guardians' => $guardians,
        ], 200);
    }

    /**
     * Get a single guardian and their wards with academic enrollments
     */
    public function show(Request $request, $id)
    {
        $guardian = Guardian::with([
            'students.courseEnrollments.course',
            'students.courseEnrollments.subjects.subject',
        ])->find($id);

        if (!$guardian) {
            return response()->json([
                'message' => 'Guardian not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Guardian retrieved successfully',
            'guardian' => $guardian,
        ], 200);
    }
}
