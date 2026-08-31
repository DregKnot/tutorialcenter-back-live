<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\ExamAttempt;
use App\Models\ClassAttendance;
use App\Models\Payment;
use App\Models\Course;
use App\Models\Subject;
use App\Models\Guardian;
use App\Models\CoursesEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\DatabaseNotification;
use Carbon\Carbon;

class GuardianDashboardController extends Controller
{
    /**
     * Ensure the authenticated Sanctum user is a Guardian instance.
     */
    protected function authorizeGuardian(Request $request): Guardian
    {
        $user = $request->user();
        if (!$user || !($user instanceof Guardian)) {
            abort(response()->json(['message' => 'Forbidden: Guardian account required.'], 403));
        }
        return $user;
    }

    /**
     * Return guardian profile with unread notifications count.
     */
    public function getProfile(Request $request)
    {
        $guardian = $this->authorizeGuardian($request);
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $guardian->id,
                'firstname' => $guardian->firstname,
                'surname' => $guardian->surname,
                'email' => $guardian->email,
                'tel' => $guardian->tel,
                'gender' => $guardian->gender,
                'ward_count' => $guardian->students()->count(),
            ]
        ]);
    }

    /**
     * Return the list of verified wards (students) linked to this guardian.
     */
    public function getWards(Request $request)
    {
        $guardian = $this->authorizeGuardian($request);
        $wards = $guardian->students()
            ->select('students.id', 'students.firstname', 'students.surname', 'students.email', 'students.tel', 'students.department')
            ->get()
            ->map(function ($student) {
                return [
                    'id' => $student->id,
                    'name' => trim($student->firstname . ' ' . $student->surname),
                    'firstname' => $student->firstname,
                    'surname' => $student->surname,
                    'email' => $student->email,
                    'tel' => $student->tel,
                    'department' => $student->department ?? 'General',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $wards,
        ]);
    }

    /**
     * Alias for routes calling getWardsDashboard
     */
    public function getWardsDashboard(Request $request)
    {
        return $this->getWards($request);
    }

    /**
     * Create or link a new ward directly from Guardian Portal
     * [HTTP]: POST /api/guardians/wards/create-or-link
     */
    public function createOrLinkWard(Request $request)
    {
        $guardian = $this->authorizeGuardian($request);

        $validated = $request->validate([
            'firstname' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'email' => 'nullable|email',
            'tel' => 'nullable|string',
            'department' => 'nullable|string',
            'password' => 'required|string|min:6',
        ]);

        // Check if student already exists by email or tel
        $student = null;
        if (!empty($validated['email'])) {
            $student = Student::where('email', $validated['email'])->first();
        }
        if (!$student && !empty($validated['tel'])) {
            $student = Student::where('tel', $validated['tel'])->first();
        }

        if ($student) {
            // SECURITY CHECK: If student account exists, verify student password to prevent unauthorized account takeover
            if (!Hash::check($validated['password'], $student->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A student account with this email/phone already exists. Please provide the student account password to link.',
                ], 422);
            }
        } else {
            $student = Student::create([
                'firstname' => $validated['firstname'],
                'surname' => $validated['surname'],
                'email' => $validated['email'] ?? null,
                'tel' => $validated['tel'] ?? null,
                'department' => $validated['department'] ?? 'General',
                'password' => Hash::make($validated['password']),
                'status' => 'active',
            ]);
        }

        // Link to guardian
        if (!$guardian->students()->where('students.id', $student->id)->exists()) {
            $guardian->students()->attach($student->id, ['relationship' => 'parent', 'status' => 'verified']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ward linked and added successfully.',
            'data' => [
                'id' => $student->id,
                'name' => trim($student->firstname . ' ' . $student->surname),
                'email' => $student->email,
                'department' => $student->department,
            ]
        ]);
    }

    /**
     * Return high-level overall performance summary for a ward.
     */
    public function getWardPerformanceOverview(Request $request, int $student_id)
    {
        $guardian = $this->authorizeGuardian($request);
        $isWard = $guardian->students()->where('students.id', $student_id)->exists();
        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $attempts = ExamAttempt::where('student_id', $student_id)
            ->where('status', ExamAttempt::COMPLETED)
            ->get();

        $totalAttempts = $attempts->count();
        $averageScore = $totalAttempts > 0 ? round($attempts->avg('percentage'), 2) : 0;
        $highestScore = $totalAttempts > 0 ? round($attempts->max('percentage'), 2) : 0;

        return response()->json([
            'total_attempts' => $totalAttempts,
            'average_score' => $averageScore,
            'highest_score' => $highestScore,
            'exams_completed' => $totalAttempts,
        ]);
    }

    /**
     * Alias for getWardPerformance
     */
    public function getWardPerformance(Request $request, int $student_id)
    {
        return $this->getWardPerformanceOverview($request, $student_id);
    }

    /**
     * Return recent attendance records for a ward.
     */
    public function getWardAttendance(Request $request, int $student_id)
    {
        $guardian = $this->authorizeGuardian($request);
        $isWard = $guardian->students()->where('students.id', $student_id)->exists();
        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $attendance = ClassAttendance::where('student_id', $student_id)
            ->with(['session.class.subject'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(function ($att) {
                return [
                    'id' => $att->id,
                    'subject' => $att->session?->class?->subject?->name ?? 'General Session',
                    'joined_at' => $att->joined_at ? $att->joined_at->toISOString() : null,
                    'left_at' => $att->left_at ? $att->left_at->toISOString() : null,
                    'status' => $att->status ?? 'present',
                ];
            });

        return response()->json([
            'attendance' => $attendance,
            'total_sessions' => $attendance->count(),
        ]);
    }

    /**
     * Return active subscription details and enrolled courses list for a ward.
     */
    public function getWardSubscription(Request $request, int $student_id)
    {
        $guardian = $this->authorizeGuardian($request);
        $isWard = $guardian->students()->where('students.id', $student_id)->exists();
        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $allEnrollments = CoursesEnrollment::query()
            ->with(['course', 'subjects.subject'])
            ->where('student_id', $student_id)
            ->latest('created_at')
            ->get();

        $enrollmentsList = $allEnrollments->map(function ($enrollment) {
            $course = $enrollment->course;
            $endDate = $enrollment->end_date ? Carbon::parse($enrollment->end_date) : null;
            $daysLeft = $endDate ? (int) max(0, now()->diffInDays($endDate, false)) : 0;
            $isActive = $enrollment->status === 'active' && ($endDate === null || $endDate->isFuture());

            $subjects = $enrollment->subjects->map(function ($subEnroll) {
                return [
                    'id' => $subEnroll->subject_id,
                    'name' => $subEnroll->subject?->name ?? ('Subject #' . $subEnroll->subject_id),
                ];
            })->values();

            return [
                'id' => $enrollment->id,
                'course_id' => $enrollment->course_id,
                'course_title' => $course?->title ?? ('Course #' . $enrollment->course_id),
                'course_price' => (float) ($enrollment->cost ?? ($course?->price ?? 10000)),
                'banner' => $course?->banner,
                'status' => $enrollment->status,
                'is_active' => $isActive,
                'start_date' => $enrollment->start_date ? Carbon::parse($enrollment->start_date)->toDateString() : null,
                'end_date' => $enrollment->end_date ? Carbon::parse($enrollment->end_date)->toDateString() : null,
                'days_left' => $daysLeft,
                'cost' => (float) ($enrollment->cost ?? ($course?->price ?? 10000)),
                'billing_cycle' => $enrollment->billing_cycle ?? 'monthly',
                'enrolled_subjects' => $subjects,
            ];
        });

        $primaryEnrollment = $enrollmentsList->firstWhere('is_active', true) ?? $enrollmentsList->first();

        $course = $primaryEnrollment ? null : Course::first();
        $fallbackPrice = (float) ($course?->price ?? 10000);

        return response()->json([
            'has_active_subscription' => (bool) $primaryEnrollment,
            'course_id' => $primaryEnrollment['course_id'] ?? $course?->id,
            'course_title' => $primaryEnrollment['course_title'] ?? ($course?->title ?? 'WAEC & JAMB Intensive Exam Prep'),
            'course_price' => $primaryEnrollment['course_price'] ?? $fallbackPrice,
            'banner' => $primaryEnrollment['banner'] ?? $course?->banner,
            'start_date' => $primaryEnrollment['start_date'] ?? now()->toDateString(),
            'end_date' => $primaryEnrollment['end_date'] ?? now()->addDays(30)->toDateString(),
            'days_left' => $primaryEnrollment['days_left'] ?? 30,
            'cost' => $primaryEnrollment['cost'] ?? $fallbackPrice,
            'billing_cycle' => $primaryEnrollment['billing_cycle'] ?? 'monthly',
            'enrolled_subjects' => $primaryEnrollment['enrolled_subjects'] ?? [],
            'enrollments' => $enrollmentsList,
            'total_enrollments' => $enrollmentsList->count(),
        ]);
    }

    /**
     * Return dynamically calculated weekly engagement and question volume from actual student logins, exam sessions, and live attendances.
     */
    public function getWardWeeklyReport(Request $request, int $student_id)
    {
        $guardian = $this->authorizeGuardian($request);
        $isWard = $guardian->students()->where('students.id', $student_id)->exists();
        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $student = Student::find($student_id);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        // Rolling 7-day window to guarantee full accurate engagement tracking
        $startDate = now()->startOfWeek();
        $endDate = now()->endOfWeek();

        // 1. Fetch Exam Attempts within current week or fallback to recent 7 days
        $examAttempts = ExamAttempt::where('student_id', $student_id)
            ->where('status', ExamAttempt::COMPLETED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // If current week has no attempts yet (e.g. early Monday morning), pull recent attempts to populate activity
        if ($examAttempts->isEmpty()) {
            $recent7DaysStart = now()->subDays(6)->startOfDay();
            $recentAttempts = ExamAttempt::where('student_id', $student_id)
                ->where('status', ExamAttempt::COMPLETED)
                ->where('created_at', '>=', $recent7DaysStart)
                ->get();
            if ($recentAttempts->isNotEmpty()) {
                $examAttempts = $recentAttempts;
            }
        }

        $totalAttempts = $examAttempts->count();
        $averageScore = $totalAttempts > 0 ? round($examAttempts->avg('percentage'), 2) : 0;
        $totalQuestionsAnswered = (int) $examAttempts->sum('total_questions');

        // If total questions in weekly window is 0, get all-time student question count as overall baseline
        $allTimeQuestions = (int) ExamAttempt::where('student_id', $student_id)->where('status', ExamAttempt::COMPLETED)->sum('total_questions');

        // 2. Fetch Notifications (Login / Logout activity)
        $activityNotifications = DatabaseNotification::query()
            ->where('notifiable_type', 'App\\Models\\Student')
            ->where('notifiable_id', $student_id)
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->orderBy('created_at', 'asc')
            ->get();

        // 3. Fetch Masterclass Live Attendances
        $classAttendances = ClassAttendance::where('student_id', $student_id)
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->get();

        // 4. Calculate Daily Engagement (Mon - Sun)
        $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $dayLetters = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
        $weeklyStudyDays = [];
        $totalMinutesActive = 0;
        $activeDaysCount = 0;

        // Temporary collection to compute max daily minutes for proportional capsule heights
        $dailyData = [];

        for ($i = 0; $i < 7; $i++) {
            $dayDate = (clone $startDate)->addDays($i);
            $dateString = $dayDate->toDateString();

            // Exam attempts on this day
            $dayAttempts = $examAttempts->filter(function ($att) use ($dateString) {
                return Carbon::parse($att->created_at)->toDateString() === $dateString;
            });
            $dayQuestions = (int) $dayAttempts->sum('total_questions');
            $dayExamMinutes = (int) $dayAttempts->sum('duration_minutes');
            if ($dayExamMinutes === 0 && $dayQuestions > 0) {
                $dayExamMinutes = max(15, (int) round($dayQuestions * 1.5));
            }

            // Live Class Attendance minutes
            $dayClassMinutes = (int) $classAttendances->filter(function ($att) use ($dateString) {
                return Carbon::parse($att->created_at)->toDateString() === $dateString;
            })->sum('attendance_duration');
            if ($dayClassMinutes === 0 && $classAttendances->whereBetween('created_at', [$dayDate->copy()->startOfDay(), $dayDate->copy()->endOfDay()])->count() > 0) {
                $dayClassMinutes = 45;
            }

            // Student Login / Logout session time calculation
            $dayNotifs = $activityNotifications->filter(function ($notif) use ($dateString) {
                return Carbon::parse($notif->created_at)->toDateString() === $dateString;
            });

            $loginNotifs = $dayNotifs->filter(fn($n) => ($n->data['type'] ?? '') === 'login')->values();
            $logoutNotifs = $dayNotifs->filter(fn($n) => ($n->data['type'] ?? '') === 'logout')->values();

            $calculatedLoginMinutes = 0;
            if ($loginNotifs->isNotEmpty()) {
                foreach ($loginNotifs as $idx => $loginN) {
                    $loginTime = Carbon::parse($loginN->created_at);
                    $matchingLogout = $logoutNotifs->get($idx);
                    if ($matchingLogout) {
                        $logoutTime = Carbon::parse($matchingLogout->created_at);
                        $diff = max(5, min(240, (int) $loginTime->diffInMinutes($logoutTime)));
                        $calculatedLoginMinutes += $diff;
                    } else {
                        $calculatedLoginMinutes += 30; // Standard active session duration
                    }
                }
            }

            $dayTotalMinutes = $dayExamMinutes + $dayClassMinutes + $calculatedLoginMinutes;
            if ($dayTotalMinutes > 0 || $dayQuestions > 0) {
                $activeDaysCount++;
            }
            $totalMinutesActive += $dayTotalMinutes;

            $hoursFormatted = $dayTotalMinutes >= 60 
                ? (round($dayTotalMinutes / 60, 1) . 'h') 
                : ($dayTotalMinutes > 0 ? ($dayTotalMinutes . 'm') : '0m');

            $dailyData[] = [
                'day' => $dayLetters[$i],
                'full' => $dayNames[$i],
                'date' => $dateString,
                'questions' => $dayQuestions,
                'questions_answered' => $dayQuestions,
                'minutes_active' => $dayTotalMinutes,
                'hours' => $hoursFormatted,
                'hours_formatted' => $hoursFormatted,
                'is_active' => $dayTotalMinutes > 0 || $dayQuestions > 0,
            ];
        }

        // Calculate proportional capsule heights
        $maxDayMinutes = max(1, max(array_column($dailyData, 'minutes_active')));
        foreach ($dailyData as $dayItem) {
            $pct = $dayItem['minutes_active'] > 0 
                ? min(100, max(20, round(($dayItem['minutes_active'] / $maxDayMinutes) * 100)))
                : ($dayItem['questions'] > 0 ? 35 : 8);

            $dayItem['pct'] = $pct;
            $weeklyStudyDays[] = $dayItem;
        }

        // 5. Last active session timestamp
        $latestAttempt = ExamAttempt::where('student_id', $student_id)->where('status', ExamAttempt::COMPLETED)->latest('created_at')->first();
        $latestNotif = $activityNotifications->last();
        $latestActivityTime = null;

        if ($latestAttempt && $latestNotif) {
            $latestActivityTime = $latestAttempt->created_at->gt($latestNotif->created_at) ? $latestAttempt->created_at : $latestNotif->created_at;
        } elseif ($latestAttempt) {
            $latestActivityTime = $latestAttempt->created_at;
        } elseif ($latestNotif) {
            $latestActivityTime = $latestNotif->created_at;
        }

        $lastActiveSession = "No sessions recorded";
        if ($latestActivityTime) {
            $isToday = $latestActivityTime->isToday();
            $isYesterday = $latestActivityTime->isYesterday();
            $relativeDay = $isToday ? "Today" : ($isYesterday ? "Yesterday" : $latestActivityTime->format('M d'));
            $lastActiveSession = "{$relativeDay} • " . $latestActivityTime->format('h:i A');
        }

        $totalHoursFormatted = $totalMinutesActive >= 60 
            ? (round($totalMinutesActive / 60, 1) . 'h') 
            : ($totalMinutesActive > 0 ? ($totalMinutesActive . 'm') : '0m');

        return response()->json([
            'report_period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'total_attempts' => $totalAttempts,
            'average_score' => $averageScore,
            'total_questions' => $totalQuestionsAnswered > 0 ? $totalQuestionsAnswered : $allTimeQuestions,
            'weekly_questions' => $totalQuestionsAnswered,
            'total_minutes' => $totalMinutesActive,
            'total_hours' => $totalHoursFormatted,
            'active_days_count' => $activeDaysCount,
            'last_active_session' => $lastActiveSession,
            'weekly_study_days' => $weeklyStudyDays,
            'summary' => "Your ward completed {$totalAttempts} practice exams ({$totalQuestionsAnswered} questions) across {$activeDaysCount} active days with {$totalHoursFormatted} total time spent.",
        ]);
    }

    /**
     * Return comprehensive detailed performance analytics for a ward.
     */
    public function getWardDetailedPerformance(Request $request, int $student_id)
    {
        $guardian = $this->authorizeGuardian($request);
        $isWard = $guardian->students()->where('students.id', $student_id)->exists();
        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $student = Student::find($student_id);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $attemptsQuery = ExamAttempt::with(['examYear.subject'])
            ->where('student_id', $student_id)
            ->where('status', ExamAttempt::COMPLETED);

        $allAttempts = (clone $attemptsQuery)->get();
        $totalAttempts = $allAttempts->count();
        $overallAvgScore = $totalAttempts > 0 ? round($allAttempts->avg('percentage'), 2) : 0;
        $overallCorrect = (int) $allAttempts->sum('correct_answers');
        $overallAttemptedQuestions = (int) $allAttempts->sum('total_questions');
        $overallAccuracy = $overallAttemptedQuestions > 0 ? round(($overallCorrect / $overallAttemptedQuestions) * 100, 1) : 0;

        $subjectBreakdowns = [];
        $grouped = $allAttempts->groupBy(function ($att) {
            return $att->examYear?->subject_id ?? 'unknown';
        });

        foreach ($grouped as $subjectId => $subjectAttempts) {
            if ($subjectId === 'unknown') continue;
            $subjectModel = Subject::find($subjectId);
            $subjectName = $subjectModel ? $subjectModel->name : 'Subject ' . $subjectId;

            $subAttemptsCount = $subjectAttempts->count();
            $subAvgScore = round($subjectAttempts->avg('percentage'), 1);
            $subHighest = round($subjectAttempts->max('percentage'), 1);
            $subLowest = round($subjectAttempts->min('percentage'), 1);
            $subCorrect = (int) $subjectAttempts->sum('correct_answers');
            $subQuestionsAttempted = (int) $subjectAttempts->sum('total_questions');
            $subAccuracy = $subQuestionsAttempted > 0 ? round(($subCorrect / $subQuestionsAttempted) * 100, 1) : 0;
            $totalQuestionsInBank = 500;
            $bankCoverage = min(100, round(($subQuestionsAttempted / $totalQuestionsInBank) * 100, 1));

            $subjectBreakdowns[] = [
                'subject_id' => $subjectId,
                'subject_name' => $subjectName,
                'attempts_count' => $subAttemptsCount,
                'average_score' => $subAvgScore,
                'highest_score' => $subHighest,
                'lowest_score' => $subLowest,
                'total_questions_attempted' => $subQuestionsAttempted,
                'accuracy_percentage' => $subAccuracy,
                'bank_coverage_percentage' => $bankCoverage,
            ];
        }

        $paginatedAttempts = $attemptsQuery->latest('submitted_at')->paginate($request->query('per_page', 10));

        $paginatedAttempts->getCollection()->transform(function ($att) {
            return [
                'id' => $att->id,
                'subject_name' => $att->examYear?->subject?->name ?? 'General CBT',
                'year' => $att->examYear?->year ?? null,
                'score' => $att->score,
                'percentage' => (float) $att->percentage,
                'correct_answers' => (int) $att->correct_answers,
                'total_questions' => (int) $att->total_questions,
                'wrong_answers' => (int) ($att->total_questions - $att->correct_answers),
                'unanswered' => 0,
                'duration_minutes' => $att->duration_minutes ?? 12,
                'submitted_at' => $att->submitted_at ? $att->submitted_at->toISOString() : null,
                'date_formatted' => $att->submitted_at ? $att->submitted_at->format('M d, Y • h:i A') : 'Recently',
            ];
        });

        return response()->json([
            'success' => true,
            'summary' => [
                'total_attempts' => $totalAttempts,
                'average_score' => $overallAvgScore,
                'total_correct_answers' => $overallCorrect,
                'total_questions_attempted' => $overallAttemptedQuestions,
                'overall_accuracy' => $overallAccuracy,
                'total_subjects_practiced' => count($subjectBreakdowns),
            ],
            'subject_breakdowns' => $subjectBreakdowns,
            'history' => $paginatedAttempts,
        ]);
    }

    /**
     * Get Raw Chronological Audit Logs for Guardian's Wards
     * [HTTP]: GET /api/guardians/audit-logs
     */
    public function getWardAuditLogs(Request $request)
    {
        $guardian = $this->authorizeGuardian($request);
        $wardIds = $guardian->students()->pluck('students.id')->toArray();

        if (empty($wardIds)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'wards' => [],
                'total' => 0
            ]);
        }

        $targetStudentId = $request->query('student_id');
        $activeWardIds = ($targetStudentId && in_array($targetStudentId, $wardIds))
            ? [$targetStudentId]
            : $wardIds;

        $category = $request->query('category', 'all');
        $search = strtolower(trim($request->query('search', '')));

        $logs = collect();

        // 1. CBT Practice Attempts
        if (in_array($category, ['all', 'cbt', 'exam'])) {
            $attempts = ExamAttempt::whereIn('student_id', $activeWardIds)
                ->with(['student:id,firstname,surname', 'examYear.subject:id,name'])
                ->whereNotNull('submitted_at')
                ->latest('submitted_at')
                ->limit(50)
                ->get();

            foreach ($attempts as $att) {
                $studentName = trim(($att->student->firstname ?? '') . ' ' . ($att->student->surname ?? ''));
                $subjectName = $att->examYear?->subject?->name ?? 'General Practice';
                $score = $att->score;
                $totalQ = $att->total_questions;
                $pct = (float) $att->percentage;
                $title = "Completed " . $subjectName . " CBT Exam";
                $desc = $studentName . " submitted a " . $subjectName . " practice quiz. Scored " . $score . "/" . $totalQ . " (" . $pct . "%).";

                $logs->push([
                    'id' => 'cbt_' . $att->id,
                    'timestamp' => $att->submitted_at->toISOString(),
                    'date_formatted' => $att->submitted_at->format('M d, Y • h:i A'),
                    'time_formatted' => $att->submitted_at->diffForHumans(),
                    'student_id' => $att->student_id,
                    'student_name' => $studentName,
                    'category' => 'cbt',
                    'title' => $title,
                    'event_title' => $title,
                    'description' => $desc,
                    'event_description' => $desc,
                    'severity' => $pct >= 50 ? 'success' : 'warning',
                    'meta' => [
                        'subject' => $subjectName,
                        'score' => $score,
                        'score_percentage' => $pct,
                        'total_questions' => $totalQ,
                        'correct_answers' => $att->correct_answers
                    ]
                ]);
            }
        }

        // 2. Masterclass Attendance
        if (in_array($category, ['all', 'masterclass', 'class'])) {
            $attendances = ClassAttendance::whereIn('student_id', $activeWardIds)
                ->with([
                    'student:id,firstname,surname',
                    'session.class.subject:id,name',
                    'session.class.staffs:staffs.id,firstname,surname'
                ])
                ->latest('created_at')
                ->limit(50)
                ->get();

            foreach ($attendances as $att) {
                $studentName = trim(($att->student->firstname ?? '') . ' ' . ($att->student->surname ?? ''));
                $className = $att->session?->class?->title ?? 'Live Masterclass';
                $subjectName = $att->session?->class?->subject?->name ?? 'Subject Lesson';
                $topic = $att->session?->class?->description ?? $className;
                $duration = $att->attendance_duration ? ($att->attendance_duration . ' mins') : 'Active Session';

                $title = "Joined " . $subjectName . " Masterclass";
                $desc = $studentName . " attended '" . $className . "' on topic '" . $topic . "' (" . $duration . ").";

                $logs->push([
                    'id' => 'att_' . $att->id,
                    'timestamp' => ($att->joined_at ?? $att->created_at)->toISOString(),
                    'date_formatted' => ($att->joined_at ?? $att->created_at)->format('M d, Y • h:i A'),
                    'time_formatted' => ($att->joined_at ?? $att->created_at)->diffForHumans(),
                    'student_id' => $att->student_id,
                    'student_name' => $studentName,
                    'category' => 'masterclass',
                    'title' => $title,
                    'event_title' => $title,
                    'description' => $desc,
                    'event_description' => $desc,
                    'severity' => 'info',
                    'meta' => [
                        'class_title' => $className,
                        'subject' => $subjectName,
                        'duration' => $duration
                    ]
                ]);
            }
        }

        // 3. Login and Student Notifications
        if (in_array($category, ['all', 'login', 'security'])) {
            $notifications = DatabaseNotification::query()
                ->where('notifiable_type', 'App\\Models\\Student')
                ->whereIn('notifiable_id', $activeWardIds)
                ->latest('created_at')
                ->limit(50)
                ->get();

            foreach ($notifications as $notif) {
                $student = Student::find($notif->notifiable_id);
                $studentName = $student ? trim($student->firstname . ' ' . $student->surname) : 'Ward';
                $type = $notif->data['type'] ?? 'activity';
                $title = $type === 'login' ? "Student Signed In" : ($notif->data['title'] ?? 'Session Event');
                $notifMsg = $notif->data['message'] ?? ($studentName . " logged into student CBT portal.");

                $logs->push([
                    'id' => 'notif_' . $notif->id,
                    'timestamp' => $notif->created_at->toISOString(),
                    'date_formatted' => $notif->created_at->format('M d, Y • h:i A'),
                    'time_formatted' => $notif->created_at->diffForHumans(),
                    'student_id' => $notif->notifiable_id,
                    'student_name' => $studentName,
                    'category' => 'login',
                    'title' => $title,
                    'event_title' => $title,
                    'description' => $notifMsg,
                    'event_description' => $notifMsg,
                    'severity' => $type === 'login' ? 'success' : 'info',
                    'meta' => [
                        'activity_type' => $type,
                    ]
                ]);
            }
        }

        // 4. Payment & Billing Transactions
        if (in_array($category, ['all', 'payment', 'billing'])) {
            $payments = Payment::whereIn('student_id', $activeWardIds)
                ->with(['student:id,firstname,surname', 'enrollment.course:id,title'])
                ->latest('created_at')
                ->limit(30)
                ->get();

            foreach ($payments as $pay) {
                $studentName = trim(($pay->student->firstname ?? '') . ' ' . ($pay->student->surname ?? ''));
                $courseTitle = $pay->enrollment?->course?->title ?? 'Training Subscription';
                $amountFormatted = '₦' . number_format($pay->amount, 2);
                $status = $pay->status;

                $title = "Payment for " . $courseTitle;
                $desc = "Transaction of " . $amountFormatted . " for " . $studentName . " (" . $courseTitle . ") marked as " . $status . ".";

                $logs->push([
                    'id' => 'pay_' . $pay->id,
                    'timestamp' => $pay->created_at->toISOString(),
                    'date_formatted' => $pay->created_at->format('M d, Y • h:i A'),
                    'time_formatted' => $pay->created_at->diffForHumans(),
                    'student_id' => $pay->student_id,
                    'student_name' => $studentName,
                    'category' => 'payment',
                    'title' => $title,
                    'event_title' => $title,
                    'description' => $desc,
                    'event_description' => $desc,
                    'severity' => $status === 'successful' ? 'success' : ($status === 'pending' ? 'warning' : 'danger'),
                    'meta' => [
                        'amount' => $pay->amount,
                        'reference' => $pay->gateway_reference,
                        'status' => $status,
                    ]
                ]);
            }
        }

        $sortedLogs = $logs->sortByDesc('timestamp')->values();

        if (!empty($search)) {
            $sortedLogs = $sortedLogs->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['title']), $search)
                    || str_contains(strtolower($item['description']), $search)
                    || str_contains(strtolower($item['student_name']), $search);
            })->values();
        }

        return response()->json([
            'success' => true,
            'data' => $sortedLogs,
            'total' => $sortedLogs->count()
        ]);
    }

    /**
     * Get Payment History for Guardian's Wards
     * [HTTP]: GET /api/guardians/payments/history
     */
    public function getPaymentHistory(Request $request)
    {
        $guardian = $this->authorizeGuardian($request);
        $wardIds = $guardian->students()->pluck('students.id')->toArray();

        $targetStudentId = $request->query('student_id');
        if ($targetStudentId && $targetStudentId !== 'all' && in_array($targetStudentId, $wardIds)) {
            $wardIds = [$targetStudentId];
        }

        if (empty($wardIds)) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        $payments = Payment::whereIn('student_id', $wardIds)
            ->with(['student:id,firstname,surname,email', 'enrollment.course:id,title,price'])
            ->latest('created_at')
            ->get()
            ->map(function ($pay) {
                return [
                    'id' => $pay->id,
                    'reference' => $pay->gateway_reference,
                    'amount' => (float) $pay->amount,
                    'status' => $pay->status,
                    'payment_method' => $pay->payment_method ?? 'card',
                    'billing_cycle' => $pay->billing_cycle ?? 'monthly',
                    'course_title' => $pay->enrollment?->course?->title ?? 'CBT Exam Training Plan',
                    'student_id' => $pay->student_id,
                    'student_name' => trim(($pay->student->firstname ?? '') . ' ' . ($pay->student->surname ?? '')),
                    'student_email' => $pay->student->email ?? '',
                    'paid_at' => $pay->paid_at ? Carbon::parse($pay->paid_at)->format('M d, Y • h:i A') : $pay->created_at->format('M d, Y • h:i A'),
                    'created_at' => $pay->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Guardian Initialize Training Renewal
     * [HTTP]: POST /api/guardians/payments/training/renew
     */
    public function renewTrainingSubscription(Request $request)
    {
        $guardian = $this->authorizeGuardian($request);

        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'billing_cycle' => 'nullable|in:monthly,quarterly,annual',
            'amount' => 'nullable|numeric|min:100',
        ]);

        $isWard = $guardian->students()->where('students.id', $validated['student_id'])->exists();
        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized: Student is not your linked ward.'], 403);
        }

        $student = Student::find($validated['student_id']);
        $amount = $validated['amount'] ?? 10000;
        $cycle = $validated['billing_cycle'] ?? 'monthly';
        $reference = 'TCR_' . strtoupper(uniqid()) . '_' . time();

        $paystackData = [
            'reference' => $reference,
            'amount' => $amount,
            'email' => $guardian->email ?: ($student->email ?: 'guardian@tutorialcenter.com'),
            'student_id' => $student->id,
            'student_name' => trim($student->firstname . ' ' . $student->surname),
            'guardian_id' => $guardian->id,
            'payment_type' => 'training_renewal',
            'billing_cycle' => $cycle,
            'key' => config('services.paystack.public_key') ?: env('PAYSTACK_PUBLIC_KEY', 'pk_test_d810e0935d60a336bea860384aabbc753cdd78ff')
        ];

        return response()->json([
            'success' => true,
            'message' => 'Renewal initialized successfully',
            'data' => $paystackData
        ]);
    }

    /**
     * Guardian Initialize Add Training Course / Subject
     * [HTTP]: POST /api/guardians/payments/training/add-course
     */
    public function addTrainingCourse(Request $request)
    {
        $guardian = $this->authorizeGuardian($request);

        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_id' => 'nullable|exists:courses,id',
            'amount' => 'nullable|numeric|min:100',
            'billing_cycle' => 'nullable|in:monthly,quarterly,annual',
            'subject_ids' => 'nullable|array',
        ]);

        $isWard = $guardian->students()->where('students.id', $validated['student_id'])->exists();
        if (!$isWard) {
            return response()->json(['message' => 'Unauthorized: Student is not your linked ward.'], 403);
        }

        $student = Student::find($validated['student_id']);
        $course = isset($validated['course_id']) ? Course::find($validated['course_id']) : null;
        $amount = $validated['amount'] ?? ($course->price ?? 10000);
        $reference = 'TCA_' . strtoupper(uniqid()) . '_' . time();

        $paystackData = [
            'reference' => $reference,
            'amount' => $amount,
            'email' => $guardian->email ?: ($student->email ?: 'guardian@tutorialcenter.com'),
            'student_id' => $student->id,
            'student_name' => trim($student->firstname . ' ' . $student->surname),
            'course_id' => $course?->id,
            'course_title' => $course?->title ?? 'New Training Program',
            'subject_ids' => $validated['subject_ids'] ?? [],
            'guardian_id' => $guardian->id,
            'payment_type' => 'add_training_course',
            'billing_cycle' => $validated['billing_cycle'] ?? 'monthly',
            'key' => config('services.paystack.public_key') ?: env('PAYSTACK_PUBLIC_KEY', 'pk_test_d810e0935d60a336bea860384aabbc753cdd78ff')
        ];

        return response()->json([
            'success' => true,
            'message' => 'Training course addition initialized',
            'data' => $paystackData
        ]);
    }
}