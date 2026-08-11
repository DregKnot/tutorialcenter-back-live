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
        // Fetch guardians with their associated students
        $guardians = Guardian::with('students')->get();
        
        return response()->json([
            'message' => 'Guardians retrieved successfully',
            'guardians' => $guardians,
        ], 200);
    }
}
