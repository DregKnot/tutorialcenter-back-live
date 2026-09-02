<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subject;
use App\Models\SubjectsEnrollment;
use App\Models\CoursesEnrollment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnrollmentAnalyticsController extends Controller
{
    /**
     * Get subject-to-student roster mapping.
     * Accessible by Admin, COO, and Course Advisor.
     * 
     * Returns:
     * - roster_map: Dictionary keyed by subject_id with list of enrolled students.
     * - most_registered_ranking: List of subjects with enrolled counts and metadata.
     * - total_unique_students: Count of distinct students enrolled in at least one subject.
     */
    public function subjectRoster(Request $request)
    {
        try {
            // Eager load active subject enrollments with student & course info
            $subjects = Subject::whereNull('deleted_at')
                ->where('status', 'active')
                ->with([
                    'subjectEnrollments' => function ($q) {
                        $q->whereNull('deleted_at')
                            ->with([
                                'student' => function ($sq) {
                                    $sq->whereNull('deleted_at')
                                       ->select('id', 'firstname', 'surname', 'email', 'avatar', 'department', 'status');
                                },
                                'enrollment.course' => function ($cq) {
                                    $cq->select('id', 'name', 'title');
                                }
                            ]);
                    }
                ])
                ->get();

            $rosterMap = [];
            $summaryList = [];
            $uniqueStudentIds = [];

            foreach ($subjects as $subject) {
                $enrolledStudents = [];
                $seenStudentIds = [];

                foreach ($subject->subjectEnrollments as $se) {
                    $student = $se->student;
                    if (!$student || in_array($student->id, $seenStudentIds)) {
                        continue;
                    }

                    $seenStudentIds[] = $student->id;
                    $uniqueStudentIds[$student->id] = true;

                    $courseName = $se->enrollment?->course?->name 
                        ?: $se->enrollment?->course?->title 
                        ?: 'General Course';

                    $enrolledStudents[] = [
                        'id' => $student->id,
                        'student_id' => $student->id,
                        'firstname' => $student->firstname,
                        'surname' => $student->surname,
                        'fullname' => trim("{$student->firstname} {$student->surname}"),
                        'email' => $student->email,
                        'avatar' => $student->avatar,
                        'department' => $student->department,
                        'status' => $student->status,
                        'course_name' => $courseName,
                        'enrolled_at' => $se->created_at?->toISOString(),
                        'progress' => $se->progress ?? 0,
                    ];
                }

                $rosterMap[$subject->id] = [
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'departments' => $subject->departments,
                    'total_enrolled' => count($enrolledStudents),
                    'students' => $enrolledStudents,
                ];

                $summaryList[] = [
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'departments' => $subject->departments,
                    'total_enrolled' => count($enrolledStudents),
                ];
            }

            // Sort summary by most registered
            usort($summaryList, fn($a, $b) => $b['total_enrolled'] <=> $a['total_enrolled']);

            return response()->json([
                'success' => true,
                'message' => 'Subject enrollment roster retrieved successfully.',
                'total_subjects' => count($summaryList),
                'total_unique_students' => count($uniqueStudentIds),
                'most_registered_ranking' => $summaryList,
                'roster_map' => $rosterMap,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('EnrollmentAnalyticsController::subjectRoster error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve subject roster.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get Ranked Leaderboard of Most Registered Subjects.
     * Accessible by Admin, COO, and Course Advisor.
     */
    public function mostRegisteredSubjects(Request $request)
    {
        try {
            $limit = (int) $request->query('limit', 15);

            $stats = Subject::whereNull('deleted_at')
                ->where('status', 'active')
                ->withCount(['subjectEnrollments' => function ($q) {
                    $q->whereNull('deleted_at');
                }])
                ->orderBy('subject_enrollments_count', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($subject) {
                    return [
                        'subject_id' => $subject->id,
                        'name' => $subject->name,
                        'departments' => $subject->departments,
                        'enrolled_count' => (int) $subject->subject_enrollments_count,
                        'banner' => $subject->banner,
                    ];
                });

            $totalEnrollments = SubjectsEnrollment::whereNull('deleted_at')->count();

            return response()->json([
                'success' => true,
                'total_subject_enrollments' => $totalEnrollments,
                'rankings' => $stats,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('EnrollmentAnalyticsController::mostRegisteredSubjects error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load most registered subjects.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Platform-wide Enrollment Analytics Overview.
     * Accessible by Admin and COO.
     */
    public function overviewAnalytics(Request $request)
    {
        try {
            $totalActiveStudents = Student::whereNull('deleted_at')->count();
            $totalCourseEnrollments = CoursesEnrollment::whereNull('deleted_at')->count();
            $totalSubjectEnrollments = SubjectsEnrollment::whereNull('deleted_at')->count();

            // Average subjects per student
            $avgSubjects = $totalActiveStudents > 0
                ? round($totalSubjectEnrollments / $totalActiveStudents, 1)
                : 0;

            // Department distribution
            $departmentCounts = Student::whereNull('deleted_at')
                ->whereNotNull('department')
                ->groupBy('department')
                ->select('department', DB::raw('count(*) as count'))
                ->pluck('count', 'department')
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_active_students' => $totalActiveStudents,
                    'total_course_enrollments' => $totalCourseEnrollments,
                    'total_subject_enrollments' => $totalSubjectEnrollments,
                    'avg_subjects_per_student' => $avgSubjects,
                    'department_distribution' => $departmentCounts,
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('EnrollmentAnalyticsController::overviewAnalytics error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load enrollment analytics overview.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
