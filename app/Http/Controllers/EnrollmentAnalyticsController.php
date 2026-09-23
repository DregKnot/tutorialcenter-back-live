<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subject;
use App\Models\SubjectsEnrollment;
use App\Models\CoursesEnrollment;
use App\Models\Course;
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

        /**
     * Get Course-Scoped Subject Hierarchy with Real Student Enrollments.
     * Grouped by Course (e.g. WAEC, JAMB, GCE), with subjects ordered by most students enrolled.
     * 
     * Accessible by Admin, COO, and Course Advisor.
     */
    public function courseSubjectHierarchy(Request $request)
    {
        try {
            $courses = Course::whereNull('deleted_at')
                ->where('status', 'active')
                ->with([
                    'subjects' => function ($q) {
                        $q->whereNull('deleted_at')
                          ->with([
                              'classes' => function ($cq) {
                                  $cq->whereNull('deleted_at')
                                     ->with(['staffs' => function ($sq) {
                                         $sq->select('staffs.id', 'staffs.firstname', 'staffs.surname', 'staffs.email', 'staffs.profile_picture', 'staffs.role');
                                     }]);
                              }
                          ]);
                    }
                ])
                ->get();

            $hierarchy = [];
            $allUniqueStudentIds = [];

            foreach ($courses as $course) {
                // Fetch all course enrollments for this course
                $courseEnrollments = CoursesEnrollment::where('course_id', $course->id)
                    ->whereNull('deleted_at')
                    ->get(['id', 'student_id', 'status', 'created_at']);

                $courseEnrollmentIds = $courseEnrollments->pluck('id')->toArray();
                $uniqueStudentIdsInCourse = $courseEnrollments->pluck('student_id')->unique()->filter()->values()->toArray();

                foreach ($uniqueStudentIdsInCourse as $sid) {
                    $allUniqueStudentIds[$sid] = true;
                }

                // Retrieve subject enrollments tied strictly to this course's enrollments
                $subjectEnrollments = SubjectsEnrollment::whereIn('course_enrollment_id', $courseEnrollmentIds)
                    ->whereNull('deleted_at')
                    ->with(['student' => function ($sq) {
                        $sq->select('id', 'firstname', 'surname', 'email', 'profile_picture', 'department');
                    }])
                    ->get();

                $enrollmentsBySubject = $subjectEnrollments->groupBy('subject_id');

                $subjectsList = [];

                foreach ($course->subjects as $subject) {
                    $enrolledList = $enrollmentsBySubject->get($subject->id, collect());

                    $students = [];
                    $seenStudentIds = [];

                    foreach ($enrolledList as $se) {
                        $st = $se->student;
                        if ($st && !in_array($st->id, $seenStudentIds)) {
                            $seenStudentIds[] = $st->id;
                            $students[] = [
                                'id' => $st->id,
                                'student_id' => $st->id,
                                'firstname' => $st->firstname,
                                'surname' => $st->surname,
                                'fullname' => trim("{$st->firstname} {$st->surname}"),
                                'email' => $st->email,
                                'avatar' => $st->profile_picture,
                                'profile_picture' => $st->profile_picture,
                                'department' => $st->department,
                                'enrolled_at' => $se->created_at?->toISOString(),
                                'progress' => $se->progress ?? 0,
                            ];
                        }
                    }

                    // Extract unique tutors across all classes of this subject
                    $tutorsMap = [];
                    foreach ($subject->classes as $cls) {
                        foreach ($cls->staffs as $staff) {
                            if (!isset($tutorsMap[$staff->id])) {
                                $tutorsMap[$staff->id] = [
                                    'id' => $staff->id,
                                    'firstname' => $staff->firstname,
                                    'surname' => $staff->surname,
                                    'fullname' => trim("{$staff->firstname} {$staff->surname}"),
                                    'email' => $staff->email,
                                    'avatar' => $staff->profile_picture,
                                    'profile_picture' => $staff->profile_picture,
                                    'role' => $staff->pivot->role ?? $staff->role,
                                ];
                            }
                        }
                    }

                    // Parse departments safely
                    $departments = is_array($subject->departments) ? $subject->departments : [];
                    if (empty($departments) && is_string($subject->departments)) {
                        $decoded = json_decode($subject->departments, true);
                        $departments = is_array($decoded) ? $decoded : [$subject->departments];
                    }

                    $subjectsList[] = [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'description' => $subject->description,
                        'banner' => $subject->banner,
                        'status' => $subject->status,
                        'departments' => $departments,
                        'tutors' => array_values($tutorsMap),
                        'classes_count' => $subject->classes->count(),
                        'classes' => $subject->classes->map(fn($c) => [
                            'id' => $c->id,
                            'title' => $c->title,
                            'status' => $c->status,
                        ]),
                        'enrolled_count' => count($students),
                        'students' => $students,
                    ];
                }

                // Sort subjects by enrolled_count DESC (most students first), tie-breaker by name
                usort($subjectsList, function ($a, $b) {
                    if ($b['enrolled_count'] === $a['enrolled_count']) {
                        return strcmp($a['name'], $b['name']);
                    }
                    return $b['enrolled_count'] <=> $a['enrolled_count'];
                });

                // Assign rank and top flag
                foreach ($subjectsList as $index => &$item) {
                    $item['rank'] = $index + 1;
                    $item['is_top_enrolled'] = ($index === 0 && $item['enrolled_count'] > 0);
                }
                unset($item);

                $hierarchy[] = [
                    'id' => $course->id,
                    'title' => $course->title,
                    'name' => $course->title,
                    'code' => strtoupper($course->slug ?: substr($course->title, 0, 4)),
                    'slug' => $course->slug,
                    'description' => $course->description,
                    'banner' => $course->banner,
                    'price' => $course->price,
                    'total_course_enrollments' => count($courseEnrollments),
                    'total_unique_students' => count($uniqueStudentIdsInCourse),
                    'total_subjects_count' => count($subjectsList),
                    'most_enrolled_subject' => !empty($subjectsList) ? [
                        'id' => $subjectsList[0]['id'],
                        'name' => $subjectsList[0]['name'],
                        'enrolled_count' => $subjectsList[0]['enrolled_count'],
                    ] : null,
                    'subjects' => $subjectsList,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Course-subject hierarchy retrieved successfully.',
                'total_courses' => count($hierarchy),
                'total_unique_students' => count($allUniqueStudentIds),
                'courses' => $hierarchy,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('EnrollmentAnalyticsController::courseSubjectHierarchy error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load course-subject hierarchy.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
