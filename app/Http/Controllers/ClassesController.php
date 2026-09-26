<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\Subject;
use App\Models\Classes;
use App\Models\ClassStaff;
use App\Models\ClassSession;
use Illuminate\Http\Request;
use App\Models\ClassSchedule;
use App\Models\ClassAttendance;
use App\Models\ClassSessionView;
use App\Models\Student;
use App\Models\CoursesEnrollment;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\ZoomService;
use App\Services\StaffNotificationService;

class ClassesController extends Controller
{

        private function getEnrolledStudentsForSubject($subjectId)
    {
        if (!$subjectId) return collect([]);
        return \App\Models\Student::query()
            ->whereHas('subjectEnrollments', function ($query) use ($subjectId) {
                $query->where('subject_id', $subjectId)
                      ->whereNull('deleted_at')
                      ->whereHas('enrollment', function ($q) {
                          $q->where('status', 'active')
                            ->where(function ($subQ) {
                                $subQ->whereNull('end_date')
                                     ->orWhere('end_date', '>=', now());
                            });
                      });
            })
            ->get(['id', 'firstname', 'surname', 'email', 'tel', 'profile_picture']);
    }

        /**
     * (admin) create a new class, class schedule, assign staff to class and class sessions
     **/
    public function store(Request $request, ZoomService $zoomService)
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',

            'staffs' => 'nullable|array',
            'staffs.*.staff_id' => 'required_with:staffs|exists:staffs,id',
            'staffs.*.role' => 'nullable|in:lead,assistant',

            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',

            'class_link' => 'nullable|url',

            'schedules' => 'required|array|min:1',
            'schedules.*.day_of_week' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'schedules.*.start_time' => 'required',
            'schedules.*.duration_minutes' => 'nullable|integer|min:1',
            'schedules.*.end_time' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Serialize creation/assignment retries for the same subject.
            Subject::whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
            $newStaffIds = [];
            /*
            |--------------------------------------------------------------------------
            | 1. Generate Class Title If Missing
            |--------------------------------------------------------------------------
            */
            $title = trim((string) $request->input('title', ''));
            if (empty($title)) {
                $subject = Subject::find($request->subject_id);
                $course = $subject ? Course::find($subject->course_id[0] ?? null) : null;

                if ($subject && $course) {
                    $title = $course->title . ' ' . $subject->name . ' Class';
                } elseif ($subject) {
                    $title = $subject->name . ' Class';
                } else {
                    $title = 'Master Class';
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Zoom Integration & Class Link
            |--------------------------------------------------------------------------
            */
            $classLink = $request->input('class_link');
            $zoomMeetingId = null;
            $zoomMeetingPassword = null;
            $zoomJoinUrl = null;
            $zoomStartUrl = null;

            if (!empty($classLink)) {
                if (preg_match('/zoom\.(?:us|com)\/(?:j|s|wc\/join)\/(\d+)/i', $classLink, $matches)) {
                    $zoomMeetingId = $matches[1];
                    $parsedUrl = parse_url($classLink);
                    if (!empty($parsedUrl['query'])) {
                        parse_str($parsedUrl['query'], $queryParams);
                        if (!empty($queryParams['pwd'])) {
                            $zoomMeetingPassword = $queryParams['pwd'];
                        }
                    }
                    $zoomJoinUrl = $classLink;
                }
            } else {
                try {
                    $subject = Subject::find($request->subject_id);
                    $topic = ($subject ? $subject->name : 'Class') . ': ' . $title;

                    $zoomMeeting = $zoomService->createMeeting($topic);

                    $zoomMeetingId = (string) ($zoomMeeting['id'] ?? '');
                    $zoomMeetingPassword = $zoomMeeting['password'] ?? null;
                    $zoomJoinUrl = $zoomMeeting['join_url'] ?? null;
                    $zoomStartUrl = $zoomMeeting['start_url'] ?? null;

                    $classLink = $zoomJoinUrl;
                } catch (\Throwable $ze) {
                    \Log::warning("Zoom meeting auto-creation during class store failed: " . $ze->getMessage());
                }
            }

            $status = $request->input('status', 'active') ?: 'active';

            /*
            |--------------------------------------------------------------------------
            | 3. Prevent Duplicate Class or Create
            |--------------------------------------------------------------------------
            */
            $class = Classes::where('subject_id', $request->subject_id)
                ->where('title', $title)
                ->first();

            if (!$class) {
                $class = Classes::create([
                    'subject_id' => $request->subject_id,
                    'title' => $title,
                    'description' => $request->description,
                    'status' => $status,
                    'zoom_meeting_id' => $zoomMeetingId,
                    'zoom_meeting_password' => $zoomMeetingPassword,
                    'zoom_join_url' => $zoomJoinUrl,
                    'zoom_start_url' => $zoomStartUrl,
                ]);
            } else {
                $class->update([
                    'description' => $request->description ?? $class->description,
                    'status' => $status,
                    'zoom_meeting_id' => $zoomMeetingId ?? $class->zoom_meeting_id,
                    'zoom_meeting_password' => $zoomMeetingPassword ?? $class->zoom_meeting_password,
                    'zoom_join_url' => $zoomJoinUrl ?? $class->zoom_join_url,
                    'zoom_start_url' => $zoomStartUrl ?? $class->zoom_start_url,
                ]);
                if (empty($classLink)) {
                    $classLink = $class->zoom_join_url;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 4. Assign Staff
            |--------------------------------------------------------------------------
            */
            if ($request->has('staffs') && is_array($request->staffs) && count($request->staffs) > 0) {
                $staffData = [];
                foreach ($request->staffs as $staff) {
                    if (!empty($staff['staff_id'])) {
                        $staffData[$staff['staff_id']] = [
                            'role' => $staff['role'] ?? 'lead'
                        ];
                    }
                }
                if (!empty($staffData)) {
                    $assignmentChanges = $class->staffs()->syncWithoutDetaching($staffData);
                    $newStaffIds = $assignmentChanges['attached'];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 5. Create Schedules + Sessions
            |--------------------------------------------------------------------------
            */
            $startDate = $request->filled('start_date')
                ? Carbon::parse($request->start_date)->startOfDay()
                : now()->startOfDay();

            $endDate = $request->filled('end_date')
                ? Carbon::parse($request->end_date)->endOfDay()
                : $startDate->copy()->addMonths(3)->endOfDay();

            $sessionsCreated = 0;

            foreach ($request->schedules as $scheduleData) {
                $parsedStart = Carbon::parse($scheduleData['start_time']);
                $startTime = $parsedStart->format('H:i');
                $duration = (int) ($scheduleData['duration_minutes'] ?? 60);
                $endTime = !empty($scheduleData['end_time'])
                    ? Carbon::parse($scheduleData['end_time'])->format('H:i')
                    : $parsedStart->copy()->addMinutes($duration)->format('H:i');

                $dayOfWeek = strtolower(trim($scheduleData['day_of_week']));

                $schedule = ClassSchedule::firstOrCreate(
                    [
                        'class_id' => $class->id,
                        'day_of_week' => $dayOfWeek,
                        'start_time' => $startTime
                    ],
                    [
                        'end_time' => $endTime,
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString()
                    ]
                );

                $current = $startDate->copy();
                if (strtolower($current->format('l')) !== $dayOfWeek) {
                    $current->next($dayOfWeek);
                }

                while ($current->lte($endDate)) {
                    $isHoliday = Holiday::whereDate('holiday_date', $current)->exists();

                    if (!$isHoliday) {
                        $session = ClassSession::firstOrCreate(
                            [
                                'class_id' => $class->id,
                                'class_schedule_id' => $schedule->id,
                                'session_date' => $current->toDateString()
                            ],
                            [
                                'starts_at' => $startTime,
                                'ends_at' => $endTime,
                                'class_link' => $classLink,
                                'status' => 'scheduled'
                            ]
                        );

                        if ($session->wasRecentlyCreated) {
                            $sessionsCreated++;
                        }
                    }

                    $current->addWeek();
                }
            }

            $class->load(['subject', 'staffs', 'schedules.sessions']);
            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Class creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
        // External delivery is deliberately outside the class transaction/error handler.
        try {
            StaffNotificationService::classAssigned($class, $newStaffIds);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Class assignment notification preparation failed', [
                'class_id' => $class->id,
                'exception' => get_class($exception),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Class created successfully',
            'sessions_created' => $sessionsCreated,
            'class' => $class,
        ], 201);
    }

    /**
     * (admin) Get classes schdule for all subjects 
    **/
        public function allClassesSchedule(Request $request){
        try {
            $staff = $request->user() ?: auth('staff')->user();

            $classes = Classes::with([
                    'subject.courses',
                    'staffs',
                    'schedules.sessions' => fn($q) => $q->withCount(['views as views']),
                    'schedules.sessions.attendances.student',
                ])
                ->whereHas('subject', fn($q) => $q->where('status', 'active'))
                ->where('status', 'active')
                ->get();

            $subjectEnrollmentCache = [];
            $classes->each(function ($class) use (&$subjectEnrollmentCache) {
                if (!isset($subjectEnrollmentCache[$class->subject_id])) {
                    $subjectEnrollmentCache[$class->subject_id] = $this->getEnrolledStudentsForSubject($class->subject_id);
                }
                $enrolledStudents = $subjectEnrollmentCache[$class->subject_id];
                $class->enrolled_students = $enrolledStudents;
                $class->enrolled_count = $enrolledStudents->count();

                if ($class->schedules) {
                    foreach ($class->schedules as $schedule) {
                        if ($schedule->sessions) {
                            foreach ($schedule->sessions as $session) {
                                $session->enrolled_students = $enrolledStudents;
                                $session->enrolled_count = $enrolledStudents->count();
                            }
                        }
                    }
                }
            });

            // Base Session Query for timeline views
            $sessionQuery = ClassSession::with([
                'class.subject',
                'class.staffs',
                'attendances.student'
            ])
            ->withCount(['views as views'])
            ->whereHas('class', fn($q) => $q->where('status', 'active'));

            $nextClass = (clone $sessionQuery)
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->first();

            $todayClasses = (clone $sessionQuery)
                ->whereDate('session_date', today())
                ->orderBy('starts_at')
                ->get();

            $weekSchedule = (clone $sessionQuery)
                ->whereBetween('session_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->get()
                ->groupBy('session_date');

            $upcomingSessions = (clone $sessionQuery)
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->limit(20)
                ->get();

            $page = max(1, (int) $request->input('page', 1));
            $perPage = max(1, min(100, (int) $request->input('per_page', 50)));
            $fetchAll = $request->boolean('all');

            $totalSessions = (clone $sessionQuery)->count();
            $lastPage = $perPage > 0 ? (int) ceil($totalSessions / $perPage) : 1;

            if ($fetchAll) {
                $allSessions = (clone $sessionQuery)
                    ->orderBy('session_date', 'desc')
                    ->orderBy('starts_at', 'desc')
                    ->get();
            } else {
                $offset = ($page - 1) * $perPage;
                $allSessions = (clone $sessionQuery)
                    ->orderBy('session_date', 'desc')
                    ->orderBy('starts_at', 'desc')
                    ->offset($offset)
                    ->limit($perPage)
                    ->get();
            }

            $formatted = $this->formatStaffScheduleResponse($staff, $nextClass, $todayClasses, $weekSchedule, $upcomingSessions, $allSessions);
            $formatted['classes'] = $classes;
            $formatted['pagination'] = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalSessions,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
            ];
            $formatted['current_page'] = $page;
            $formatted['per_page'] = $perPage;
            $formatted['total'] = $totalSessions;
            $formatted['last_page'] = $lastPage;
            $formatted['has_more'] = $page < $lastPage;

            return response()->json($formatted);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch classes schedule',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * (Public) View a specific class schedule with sessions
     * - Used for students to view class details and join links 
    **/
    public function viewClassSchedule(int $classId): JsonResponse
    {
        try {
            $class = Classes::with(['subject', 'staffs', 'schedules.sessions'])->where('id', $classId)->where('status', 'active')->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Class schedule fetched successfully',
                'class' => $class,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch class schedule',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update recording link for a class session (Admin, Advisor, or Staff)
     */
    public function updateSessionRecording(Request $request): JsonResponse{
        try {
            $validator = Validator::make($request->all(), [
                'session_id' => 'required|exists:class_sessions,id',
                'recording_link' => 'required|url',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $session = ClassSession::findOrFail($request->session_id);
            $class = $session->class;

            // Get authenticated staff
            $staff = auth('staff')->user();

            if (!$staff) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            // Check if staff is admin or assigned to the class
            $isAdmin = $staff->role === 'admin';
            $isAssigned = ClassStaff::where('class_id', $class->id)
                ->where('staff_id', $staff->id)
                ->exists();

            if (!$isAdmin && !$isAssigned) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to update this session',
                ], 403);
            }

            $endsAt = \App\Services\StudentNotificationService::sessionTime($session, 'ends_at');
            if (! $endsAt || ! $endsAt->isPast()) {
                return response()->json(['success' => false, 'message' => 'Recordings can only be added after the session has ended.'], 422);
            }

            // Update the recording link
            $session->update([
                'recording_link' => $request->recording_link,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Recording link updated successfully',
                'session' => $session,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update recording link',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get recorded classes for the authenticated user (student or staff)
     */
    public function getRecordedClasses(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $isStaff = $user instanceof Staff;
            $studentId = $user instanceof Student ? $user->id : null;

            // Base query for class sessions that are past and have a recording link
            $query = ClassSession::with([
                'class.staffs', 
                'class.subject.courses',
                'views.student' => function ($sq) {
                    $sq->select('id', 'firstname', 'surname', 'profile_picture');
                }
            ])
            ->whereNotNull('recording_link')
            ->where('recording_link', '!=', '')
            ->where(function ($q) {
                $q->whereDate('session_date', '<', today())
                    ->orWhere(function ($q2) {
                        $q2->whereDate('session_date', today())
                            ->where('ends_at', '<', now()->format('H:i:s'));
                    });
            });

            $sessions = $query->orderBy('updated_at', 'desc')->get();

            // Enrollment & Course-level isolation
            if ($user instanceof Student) {
                $activeEnrollments = CoursesEnrollment::with('course')
                    ->where('student_id', $user->id)
                    ->where('status', 'active')
                    ->get();

                if ($activeEnrollments->isEmpty()) {
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'message' => 'No active course enrollments found.',
                    ]);
                }

                $enrolledCourseIds = $activeEnrollments->pluck('course_id')->filter()->toArray();
                $enrolledCourseTitles = $activeEnrollments->map(function ($e) {
                    return strtoupper(trim($e->course?->title ?? ''));
                })->filter()->values()->toArray();
                $enrolledCourseCodes = $activeEnrollments->map(function ($e) {
                    return strtoupper(trim($e->course?->slug ?? ''));
                })->filter()->values()->toArray();
                $enrolledCourseTerms = array_values(array_unique(array_filter(array_merge($enrolledCourseTitles, $enrolledCourseCodes))));

                $sessions = $sessions->filter(function ($session) use ($enrolledCourseTerms, $enrolledCourseIds) {
                    $classTitle = strtoupper($session->class?->title ?? '');
                    $sessionTitle = strtoupper($session->title ?? '');

                    // Layer 1: Title matching (Primary - system guarantees "GCE - ...", "JAMB - ...")
                    foreach ($enrolledCourseTerms as $term) {
                        if (!empty($term) && (str_contains($classTitle, $term) || str_contains($sessionTitle, $term))) {
                            return true;
                        }
                    }

                    // Layer 2: Subject-to-Course relational safety net (Ultimate Fallback)
                    $subjectCourseIds = $session->class?->subject?->courses->pluck('id')->toArray() ?? [];
                    if (!empty(array_intersect($enrolledCourseIds, $subjectCourseIds))) {
                        return true;
                    }

                    return false;
                });
            } elseif ($isStaff && $request->filled('course_id')) {
                $filterCourseId = (int) $request->query('course_id');
                $filterCourse = Course::find($filterCourseId);
                $filterTitle = $filterCourse ? strtoupper(trim($filterCourse->title)) : null;

                $sessions = $sessions->filter(function ($session) use ($filterCourseId, $filterTitle) {
                    $classTitle = strtoupper($session->class?->title ?? '');
                    $sessionTitle = strtoupper($session->title ?? '');

                    if ($filterTitle && (str_contains($classTitle, $filterTitle) || str_contains($sessionTitle, $filterTitle))) {
                        return true;
                    }
                    $subjectCourseIds = $session->class?->subject?->courses->pluck('id')->toArray() ?? [];
                    return in_array($filterCourseId, $subjectCourseIds);
                });
            }

            $recordedClasses = $sessions->map(function ($session) use ($studentId, $isStaff) {
                $class = $session->class;
                
                // Get tutor name
                $tutorName = "Instructor";
                if ($class && $class->staffs->isNotEmpty()) {
                    $tutor = $class->staffs->first();
                    $tutorName = $tutor->firstname . ' ' . $tutor->surname;
                }

                // Get subject
                $subject = $class ? ($class->subject ? $class->subject->name : "General") : "General";

                // Format clean title without repeating tutor and subject
                $topic = $session->title ?: ($class ? $class->title : "$subject Class");
                $formattedTitle = $topic;

                // Determine course info for badge display
                $courseInfo = null;
                $linkedCourse = $class?->subject?->courses?->first();
                if ($linkedCourse) {
                    $courseInfo = [
                        'id' => $linkedCourse->id,
                        'title' => $linkedCourse->title,
                        'name' => $linkedCourse->title,
                    ];
                } else {
                    $rawTitle = $class?->title ?? $session->title ?? '';
                    if (preg_match('/^(JAMB|WAEC|NECO|GCE)/i', trim($rawTitle), $m)) {
                        $courseInfo = [
                            'id' => null,
                            'title' => strtoupper($m[1]),
                            'name' => strtoupper($m[1]),
                        ];
                    }
                }

                // Calculate duration
                $duration = "1h";
                if ($session->starts_at && $session->ends_at) {
                    $start = \Carbon\Carbon::parse($session->starts_at);
                    $end = \Carbon\Carbon::parse($session->ends_at);
                    $diffInMinutes = $start->diffInMinutes($end);
                    if ($diffInMinutes >= 60) {
                        $hours = floor($diffInMinutes / 60);
                        $mins = $diffInMinutes % 60;
                        $duration = $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
                    } else {
                        $duration = "{$diffInMinutes}m";
                    }
                }

                // Extract youtube video ID if it's a youtube link
                $videoId = null;
                $url = $session->recording_link;
                if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i', $url, $match)) {
                    $videoId = $match[1];
                }

                // Cumulative view metrics across all students
                $totalViews = (int) $session->views->sum('view_count');
                $uniqueViewers = (int) $session->views->count();
                $myView = $studentId ? $session->views->firstWhere('student_id', $studentId) : null;

                // Viewers breakdown roster (ONLY accessible to Staff/Admin; stripped for Students)
                $viewersList = [];
                if ($isStaff) {
                    $viewersList = $session->views->map(function ($v) {
                        $st = $v->student;
                        return [
                            'student_id' => $v->student_id,
                            'name' => $st ? trim("{$st->firstname} {$st->surname}") : 'Student',
                            'firstname' => $st?->firstname ?? '',
                            'surname' => $st?->surname ?? '',
                            'avatar' => $st?->profile_picture,
                            'view_count' => (int) $v->view_count,
                            'is_repeat_viewer' => $v->view_count > 1,
                            'first_viewed_at' => $v->first_viewed_at ? $v->first_viewed_at->toISOString() : null,
                            'last_viewed_at' => $v->last_viewed_at ? $v->last_viewed_at->toISOString() : null,
                        ];
                    })->sortByDesc('view_count')->values()->all();
                }

                $savedDate = $session->updated_at ?: ($session->session_date ?: $session->created_at);
                $formattedDate = $savedDate 
                    ? \Carbon\Carbon::parse($savedDate)->format('M j, Y') 
                    : ($session->session_date ? \Carbon\Carbon::parse($session->session_date)->format('M j, Y') : now()->format('M j, Y'));

                return [
                    'id' => $session->id,
                    'title' => $formattedTitle,
                    'topic' => $topic,
                    'subject' => $subject,
                    'course' => $courseInfo,
                    'course_name' => $courseInfo ? $courseInfo['title'] : null,
                    'tutor' => $tutorName,
                    'date' => $formattedDate,
                    'saved_at' => $session->updated_at ? $session->updated_at->toISOString() : null,
                    'session_date' => $session->session_date ? \Carbon\Carbon::parse($session->session_date)->toDateString() : null,
                    'duration' => $duration,
                    'videoUrl' => $url,
                    'videoId' => $videoId,
                    'thumbnail' => $videoId ? "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg" : null,
                    'views' => $totalViews,
                    'total_views' => $totalViews,
                    'unique_viewers' => $uniqueViewers,
                    'my_views' => $myView ? (int) $myView->view_count : 0,
                    'view_count' => $myView ? (int) $myView->view_count : 0,
                    'viewers' => $viewersList,
                    'color' => 'from-blue-600 to-indigo-600'
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $recordedClasses
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recorded classes',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function recordRecordingView(Request $request, ClassSession $classSession): JsonResponse
    {
        try {
            $student = $request->user();

            if (! $student instanceof Student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only students can record video views.',
                ], 403);
            }

            if (blank($classSession->recording_link)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This class has no recording available.',
                ], 422);
            }

            $view = ClassSessionView::firstOrNew([
                'class_session_id' => $classSession->id,
                'student_id' => $student->id,
            ]);

            // Debounce requirement: rewatch must be at least 10 minutes watch time
            $debounceMinutes = (int) config('services.recording_views.debounce_minutes', 10);
            $withinDebounce = $view->last_viewed_at
                && $view->last_viewed_at->gt(now()->subMinutes($debounceMinutes));

            if (! $view->exists) {
                $view->view_count = 1;
                $view->first_viewed_at = now();
                $view->last_viewed_at = now();
                $view->save();
            } elseif (! $withinDebounce) {
                $view->view_count = (int) $view->view_count + 1;
                $view->last_viewed_at = now();
                $view->save();
            }

            // Total views across all students for this session
            $totalViews = (int) ClassSessionView::where('class_session_id', $classSession->id)->sum('view_count');
            $uniqueViewers = (int) ClassSessionView::where('class_session_id', $classSession->id)->count();

            return response()->json([
                'success' => true,
                'message' => 'View recorded.',
                'data' => [
                    'class_session_id' => $classSession->id,
                    'views' => $totalViews,
                    'total_views' => $totalViews,
                    'unique_viewers' => $uniqueViewers,
                    'view_count' => (int) $view->view_count,
                    'my_views' => (int) $view->view_count,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record view',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Admin/Staff: get full viewer engagement roster for a recorded class session.
     */
    public function getSessionViewers(Request $request, ClassSession $classSession): JsonResponse
    {
        try {
            $classSession->load([
                'views.student' => function ($sq) {
                    $sq->select('id', 'firstname', 'surname', 'profile_picture');
                },
                'class.subject',
            ]);

            $totalViews = (int) $classSession->views->sum('view_count');
            $uniqueViewers = (int) $classSession->views->count();
            $repeatViewers = (int) $classSession->views->filter(fn($v) => $v->view_count > 1)->count();

            $viewers = $classSession->views->map(function ($v) {
                $st = $v->student;
                return [
                    'student_id' => $v->student_id,
                    'name' => $st ? trim("{$st->firstname} {$st->surname}") : 'Student',
                    'firstname' => $st?->firstname ?? '',
                    'surname' => $st?->surname ?? '',
                    'avatar' => $st?->profile_picture,
                    'view_count' => (int) $v->view_count,
                    'is_repeat_viewer' => $v->view_count > 1,
                    'first_viewed_at' => $v->first_viewed_at ? $v->first_viewed_at->toISOString() : null,
                    'last_viewed_at' => $v->last_viewed_at ? $v->last_viewed_at->toISOString() : null,
                ];
            })->sortByDesc('view_count')->values()->all();

            return response()->json([
                'success' => true,
                'data' => [
                    'class_session_id' => $classSession->id,
                    'session_title' => $classSession->title ?: ($classSession->class?->title ?? 'Recorded Class'),
                    'subject' => $classSession->class?->subject?->name ?? 'General',
                    'total_views' => $totalViews,
                    'unique_viewers' => $uniqueViewers,
                    'repeat_viewers' => $repeatViewers,
                    'viewers' => $viewers,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load session viewers',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function update(Request $request, $id, ZoomService $zoomService){
        $validator = Validator::make($request->all(), [
            'subject_id' => 'nullable|exists:subjects,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',

            'staffs' => 'nullable|array',
            'staffs.*.staff_id' => 'required_with:staffs|exists:staffs,id',
            'staffs.*.role' => 'nullable|string|max:100',

            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            
            'class_link' => 'nullable|url',

            'schedules' => 'nullable|array',
            'schedules.*.day_of_week' => 'required_with:schedules|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'schedules.*.start_time' => 'required_with:schedules',
            'schedules.*.duration_minutes' => 'nullable|integer|min:1',
            'schedules.*.end_time' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $class = Classes::with(['staffs', 'schedules.sessions'])->findOrFail($id);

            $updateData = [];
            if ($request->has('subject_id') && !empty($request->subject_id)) {
                $updateData['subject_id'] = $request->subject_id;
            }
            if ($request->has('title')) {
                $updateData['title'] = $request->title;
            }
            if ($request->has('description')) {
                $updateData['description'] = $request->description;
            }
            if ($request->has('status')) {
                $updateData['status'] = $request->status;
            }
            if ($request->has('class_link')) {
                $updateData['class_link'] = $request->class_link;
                if (!empty($request->class_link) && preg_match('/zoom\.(?:us|com)\/(?:j|s|wc\/join)\/(\d+)/i', $request->class_link, $matches)) {
                    $updateData['zoom_meeting_id'] = $matches[1];
                    $parsedUrl = parse_url($request->class_link);
                    if (!empty($parsedUrl['query'])) {
                        parse_str($parsedUrl['query'], $queryParams);
                        if (!empty($queryParams['pwd'])) {
                            $updateData['zoom_meeting_password'] = $queryParams['pwd'];
                        }
                    }
                    $updateData['zoom_join_url'] = $request->class_link;
                }
            }

            if (!empty($updateData)) {
                $class->update($updateData);
            }

            // Sync assigned staff (only if non-empty staffs list is provided, preserving existing staff otherwise)
            if ($request->has('staffs') && is_array($request->staffs) && count($request->staffs) > 0) {
                $staffData = [];
                foreach ($request->staffs as $staff) {
                    if (!empty($staff['staff_id'])) {
                        $staffData[$staff['staff_id']] = [
                            'role' => $staff['role'] ?? 'tutor'
                        ];
                    }
                }
                if (!empty($staffData)) {
                    $class->staffs()->sync($staffData);
                }
            }

            // If schedules or dates are provided, alter future sessions while preserving past attendance
            if ($request->has('schedules') && is_array($request->schedules) && count($request->schedules) > 0) {
                $startDate = $request->filled('start_date') 
                    ? Carbon::parse($request->start_date)->startOfDay() 
                    : Carbon::parse($class->schedules->min('start_date') ?? now())->startOfDay();

                $endDate = $request->filled('end_date') 
                    ? Carbon::parse($request->end_date)->endOfDay() 
                    : Carbon::parse($class->schedules->max('end_date') ?? now()->addMonths(3))->endOfDay();

                $todayStr = today()->toDateString();

                // Delete future scheduled sessions that do not have attendance recorded
                ClassSession::where('class_id', $class->id)
                    ->whereDate('session_date', '>=', $todayStr)
                    ->whereDoesntHave('attendances')
                    ->delete();

                // Delete existing schedules (they will be recreated)
                ClassSchedule::where('class_id', $class->id)->delete();

                $classLink = $request->class_link ?? $class->zoom_join_url ?? $class->class_link;

                foreach ($request->schedules as $scheduleData) {
                    $startTime = $scheduleData['start_time'];
                    $duration = $scheduleData['duration_minutes'] ?? 60;
                    
                    $endTime = !empty($scheduleData['end_time']) 
                        ? $scheduleData['end_time']
                        : Carbon::createFromFormat('H:i', $startTime)->addMinutes($duration)->format('H:i');

                    $schedule = ClassSchedule::create([
                        'class_id' => $class->id,
                        'day_of_week' => strtolower(trim($scheduleData['day_of_week'])),
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString()
                    ]);

                    // Generate upcoming sessions starting from today (or start_date if in future)
                    $iterationStart = $startDate->greaterThan(today()) ? $startDate->copy() : today();
                    $targetDay = strtolower(trim($scheduleData['day_of_week']));

                    $current = $iterationStart->copy();
                    if (strtolower($current->format('l')) !== $targetDay) {
                        $current->next($targetDay);
                    }

                    while ($current->lte($endDate)) {
                        $isHoliday = Holiday::whereDate('holiday_date', $current)->exists();
                        if (!$isHoliday) {
                            ClassSession::firstOrCreate(
                                [
                                    'class_id' => $class->id,
                                    'class_schedule_id' => $schedule->id,
                                    'session_date' => $current->toDateString()
                                ],
                                [
                                    'starts_at' => $startTime,
                                    'ends_at' => $endTime,
                                    'class_link' => $classLink,
                                    'status' => 'scheduled'
                                ]
                            );
                        }
                        $current->addWeek();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Masterclass and schedules updated successfully',
                'class' => $class->fresh([
                    'subject',
                    'staffs',
                    'schedules.sessions'
                ])
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Class update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * (admin) deactivate class and delete future unattended sessions
    **/
    public function destroy($id){
        try {
            $class = Classes::findOrFail($id);
            $class->update(['status' => 'inactive']);
            
            ClassSession::where('class_id', $class->id)
                ->whereDate('session_date', '>=', today())
                ->whereDoesntHave('attendances')
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Class deactivated and upcoming sessions removed successfully'
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate class',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    /**
     * (tutor) Get tutor schedule with assigned classes
    **/
    /**
     * (tutor) Get tutor schedule with assigned classes - unified with center master schedule
    **/
    public function tutorClassesSchedule(Request $request){
        try {
            $staff = $request->user() ?: auth('staff')->user();

            if (!$staff) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized tutor'
                ], 401);
            }

            // If explicitly requesting all center classes (e.g. from calendar view ?all=true or scope=all), return center-wide schedule
            if ($request->boolean('all') || $request->input('scope') === 'all') {
                return $this->allClassesSchedule($request);
            }

            // 1. Query only classes where this tutor is assigned in class_staff
            $classes = Classes::with([
                    'subject.courses',
                    'staffs',
                    'schedules.sessions' => fn($q) => $q->withCount(['views as views']),
                    'schedules.sessions.attendances.student',
                ])
                ->whereHas('subject', fn($q) => $q->where('status', 'active'))
                ->where('status', 'active')
                ->whereHas('staffs', fn($q) => $q->where('staffs.id', $staff->id))
                ->get();

            $subjectEnrollmentCache = [];
            $classes->each(function ($class) use (&$subjectEnrollmentCache) {
                if (!isset($subjectEnrollmentCache[$class->subject_id])) {
                    $subjectEnrollmentCache[$class->subject_id] = $this->getEnrolledStudentsForSubject($class->subject_id);
                }
                $enrolledStudents = $subjectEnrollmentCache[$class->subject_id];
                $class->enrolled_students = $enrolledStudents;
                $class->enrolled_count = $enrolledStudents->count();

                if ($class->schedules) {
                    foreach ($class->schedules as $schedule) {
                        if ($schedule->sessions) {
                            foreach ($schedule->sessions as $session) {
                                $session->enrolled_students = $enrolledStudents;
                                $session->enrolled_count = $enrolledStudents->count();
                            }
                        }
                    }
                }
            });

            // 2. Base Session Query for timeline views (only sessions belonging to tutor's classes)
            $sessionQuery = ClassSession::with([
                'class.subject',
                'class.staffs',
                'attendances.student'
            ])
            ->withCount(['views as views'])
            ->whereHas('class', function ($q) use ($staff) {
                $q->where('status', 'active')
                  ->whereHas('staffs', fn($qs) => $qs->where('staffs.id', $staff->id));
            });

            // Smart Next Up session ordering: future dates or today where class is still upcoming
            $nowTime = now()->format('H:i:s');
            $todayDate = today()->toDateString();

            $nextClass = (clone $sessionQuery)
                ->where(function ($q) use ($todayDate, $nowTime) {
                    $q->whereDate('session_date', '>', $todayDate)
                      ->orWhere(function ($q2) use ($todayDate, $nowTime) {
                          $q2->whereDate('session_date', $todayDate)
                             ->where(function ($q3) use ($nowTime) {
                                 $q3->where('ends_at', '>=', $nowTime)
                                    ->orWhere(function ($q4) use ($nowTime) {
                                        $q4->whereNull('ends_at')
                                           ->where('starts_at', '>=', $nowTime);
                                    });
                             });
                      });
                })
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->orderBy('session_date', 'asc')
                ->orderBy('starts_at', 'asc')
                ->first();

            // Fallback if no upcoming session found today or future
            if (!$nextClass) {
                $nextClass = (clone $sessionQuery)
                    ->whereDate('session_date', '>=', $todayDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->orderBy('session_date', 'asc')
                    ->orderBy('starts_at', 'asc')
                    ->first();
            }

            $todayClasses = (clone $sessionQuery)
                ->whereDate('session_date', today())
                ->orderBy('starts_at')
                ->get();

            $weekSchedule = (clone $sessionQuery)
                ->whereBetween('session_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->get()
                ->groupBy('session_date');

            $upcomingSessions = (clone $sessionQuery)
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->limit(20)
                ->get();

            $page = max(1, (int) $request->input('page', 1));
            $perPage = max(1, min(100, (int) $request->input('per_page', 50)));

            $totalSessions = (clone $sessionQuery)->count();
            $lastPage = $perPage > 0 ? (int) ceil($totalSessions / $perPage) : 1;

            $offset = ($page - 1) * $perPage;
            $allSessions = (clone $sessionQuery)
                ->orderBy('session_date', 'desc')
                ->orderBy('starts_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $formatted = $this->formatStaffScheduleResponse($staff, $nextClass, $todayClasses, $weekSchedule, $upcomingSessions, $allSessions);
            $formatted['classes'] = $classes;
            $formatted['pagination'] = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalSessions,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
            ];
            $formatted['current_page'] = $page;
            $formatted['per_page'] = $perPage;
            $formatted['total'] = $totalSessions;
            $formatted['last_page'] = $lastPage;
            $formatted['has_more'] = $page < $lastPage;

            return response()->json($formatted);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch tutor classes schedule',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * (advisor) Get advisor schedule - unified with center master schedule
    **/
    public function advisorClassesSchedule(Request $request){
        return $this->allClassesSchedule($request);
    }

    /**
     * (student) Get student schedule with attendance status
    **/
    public function studentClassSchedule(Request $request){
        try {
            $student = $request->user() ?: auth('student')->user();

            if (!$student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized student'
                ], 401);
            }

            // 1. Get Subject IDs registered by this student
            $subjectIds = $student->subjectEnrollments()
                ->whereNull('deleted_at')
                ->pluck('subject_id')
                ->unique()
                ->values();

            // If empty, check active course enrollments
            if ($subjectIds->isEmpty()) {
                $courseIds = $student->courseEnrollments()
                    ->where('status', 'active')
                    ->pluck('course_id');

                if ($courseIds->isNotEmpty()) {
                    $subjectIds = \App\Models\Subject::whereHas('courses', function($q) use ($courseIds) {
                        $q->whereIn('courses.id', $courseIds);
                    })->pluck('id');
                }
            }

            // 2. Base Query for Class Sessions
            $sessionQuery = ClassSession::with([
                'class.subject',
                'class.staffs',
                'attendances' => function($q) use ($student) {
                    $q->where('student_id', $student->id);
                }
            ])
            ->whereHas('class', function ($q) use ($subjectIds) {
                if ($subjectIds->isNotEmpty()) {
                    $q->whereIn('subject_id', $subjectIds);
                }
                $q->where('status', 'active');
            });

            // If subjectIds still empty, fallback to active classes so calendar isn't blank
            if ($subjectIds->isEmpty()) {
                $sessionQuery = ClassSession::with([
                    'class.subject',
                    'class.staffs',
                    'attendances' => function($q) use ($student) {
                        $q->where('student_id', $student->id);
                    }
                ])
                ->whereHas('class', fn($q) => $q->where('status', 'active'));
            }

            $transformStudentSession = function($session) use ($student) {
                if (!$session) return null;
                $class = $session->class;

                $tutor = null;
                if ($class && $class->staffs && $class->staffs->isNotEmpty()) {
                    $leadStaff = $class->staffs->first(function($s) {
                        $role = strtolower($s->pivot->role ?? $s->role ?? '');
                        return $role === 'lead' || $role === 'tutor';
                    }) ?: $class->staffs->first();
                    $tutor = $leadStaff;
                }

                $myAttendance = $session->attendances ? $session->attendances->first() : null;

                $tutorData = $tutor ? [
                    'id' => $tutor->id,
                    'firstname' => $tutor->firstname ?? '',
                    'surname' => $tutor->surname ?? '',
                    'name' => trim(($tutor->firstname ?? '') . ' ' . ($tutor->surname ?? '')),
                    'email' => $tutor->email ?? '',
                    'avatar' => $tutor->profile_picture ?? null,
                    'profile_picture' => $tutor->profile_picture ?? null,
                    'role' => $tutor->pivot->role ?? $tutor->role ?? 'tutor',
                ] : [
                    'id' => null,
                    'firstname' => 'Tutorial Center',
                    'surname' => 'Tutor',
                    'name' => 'Tutorial Center Tutor',
                    'email' => '',
                    'avatar' => null,
                    'profile_picture' => null,
                    'role' => 'tutor',
                ];

                $tutorName = $tutor ? trim(($tutor->firstname ?? '') . ' ' . ($tutor->surname ?? '')) : 'Tutorial Center Tutor';

                $classStaffs = ($class && $class->staffs) ? $class->staffs->map(function($st) {
                    return [
                        'id' => $st->id,
                        'firstname' => $st->firstname ?? '',
                        'surname' => $st->surname ?? '',
                        'name' => trim(($st->firstname ?? '') . ' ' . ($st->surname ?? '')),
                        'email' => $st->email ?? '',
                        'profile_picture' => $st->profile_picture ?? null,
                        'avatar' => $st->profile_picture ?? null,
                        'role' => $st->pivot->role ?? $st->role ?? 'tutor',
                    ];
                })->values()->all() : [];

                return [
                    'id' => $session->id,
                    'class_id' => $session->class_id,
                    'title' => $session->title ?: ($class ? $class->title : 'Master Class'),
                    'topic' => $session->title ?: ($class ? $class->title : 'Master Class'),
                    'subject_name' => $class && $class->subject ? $class->subject->name : 'General',
                    'subject' => $class ? $class->subject : null,
                    'class' => $class ? [
                        'id' => $class->id,
                        'title' => $class->title,
                        'description' => $class->description ?? null,
                        'status' => $class->status ?? 'active',
                        'subject_id' => $class->subject_id ?? null,
                        'staffs' => $classStaffs,
                    ] : null,
                    'session_date' => $session->session_date ? \Carbon\Carbon::parse($session->session_date)->toDateString() : null,
                    'starts_at' => $session->starts_at ? substr($session->starts_at, 0, 5) : '10:00',
                    'ends_at' => $session->ends_at ? substr($session->ends_at, 0, 5) : '11:30',
                    'class_link' => $session->class_link ?: ($class ? ($class->zoom_join_url ?: $class->class_link) : null),
                    'recording_link' => $session->recording_link,
                    'tutor' => $tutorData,
                    'tutor_name' => $tutorName,
                    'status' => $session->status ?? 'scheduled',
                    'my_attendance' => $myAttendance ? [
                        'status' => $myAttendance->status,
                        'joined_at' => $myAttendance->joined_at,
                    ] : null,
                ];
            };

            $nowTime = now()->format('H:i:s');
            $todayDate = today()->toDateString();

            // Next active/upcoming sessions:
            // 1. Must be on a future date, OR today where ends_at is in the future (or starts_at if ends_at is null)
            // 2. Status not in ('completed', 'cancelled')
            // 3. Strictly ordered by session_date ASC, then starts_at ASC (sooner classes come first)
            $nextClassesRaw = (clone $sessionQuery)
                ->where(function ($q) use ($todayDate, $nowTime) {
                    $q->whereDate('session_date', '>', $todayDate)
                      ->orWhere(function ($q2) use ($todayDate, $nowTime) {
                          $q2->whereDate('session_date', $todayDate)
                             ->where(function ($q3) use ($nowTime) {
                                 $q3->where('ends_at', '>=', $nowTime)
                                    ->orWhere(function ($q4) use ($nowTime) {
                                        $q4->whereNull('ends_at')
                                           ->where('starts_at', '>=', $nowTime);
                                    });
                             });
                      });
                })
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->orderBy('session_date', 'asc')
                ->orderBy('starts_at', 'asc')
                ->take(2)
                ->get();

            // If no future sessions today/beyond, fallback to today onwards
            if ($nextClassesRaw->isEmpty()) {
                $nextClassesRaw = (clone $sessionQuery)
                    ->whereDate('session_date', '>=', $todayDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->orderBy('session_date', 'asc')
                    ->orderBy('starts_at', 'asc')
                    ->take(2)
                    ->get();
            }

            $nextClassRaw = $nextClassesRaw->first();

            $todayClassesRaw = (clone $sessionQuery)
                ->whereDate('session_date', today())
                ->orderBy('starts_at')
                ->get();

            $weekScheduleRaw = (clone $sessionQuery)
                ->whereBetween('session_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->get();

            $weekScheduleGrouped = [];
            foreach ($weekScheduleRaw as $s) {
                $d = \Carbon\Carbon::parse($s->session_date)->toDateString();
                if (!isset($weekScheduleGrouped[$d])) {
                    $weekScheduleGrouped[$d] = [];
                }
                $weekScheduleGrouped[$d][] = $transformStudentSession($s);
            }

            $upcomingSessionsRaw = (clone $sessionQuery)
                ->whereDate('session_date', '>=', today())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->limit(20)
                ->get();

            $olderSessionsRaw = (clone $sessionQuery)
                ->whereDate('session_date', '<', today())
                ->orderBy('session_date', 'desc')
                ->orderBy('starts_at', 'desc')
                ->limit(20)
                ->get();

            $page = max(1, (int) $request->input('page', 1));
            $perPage = max(1, min(100, (int) $request->input('per_page', 50)));
            $fetchAll = $request->boolean('all');

            $totalSessions = (clone $sessionQuery)->count();
            $lastPage = $perPage > 0 ? (int) ceil($totalSessions / $perPage) : 1;

            if ($fetchAll) {
                $allSessionsRaw = (clone $sessionQuery)
                    ->orderBy('session_date', 'desc')
                    ->orderBy('starts_at', 'desc')
                    ->get();
            } else {
                $offset = ($page - 1) * $perPage;
                $allSessionsRaw = (clone $sessionQuery)
                    ->orderBy('session_date', 'desc')
                    ->orderBy('starts_at', 'desc')
                    ->offset($offset)
                    ->limit($perPage)
                    ->get();
            }

            return response()->json([
                'success' => true,
                'next_class' => $nextClassRaw ? $transformStudentSession($nextClassRaw) : null,
                'next_classes' => $nextClassesRaw->map($transformStudentSession)->values(),
                'today_classes' => $todayClassesRaw->map($transformStudentSession),
                'week_schedule' => $weekScheduleGrouped,
                'upcoming_sessions' => $upcomingSessionsRaw->map($transformStudentSession),
                'older_sessions' => $olderSessionsRaw->map($transformStudentSession),
                'sessions' => $allSessionsRaw->map($transformStudentSession),
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalSessions,
                    'last_page' => $lastPage,
                    'has_more' => $page < $lastPage,
                ],
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalSessions,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch student schedule',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * (student) Get student calendar schedule
    **/
    public function studentCalenderSchedule(Request $request){
        return $this->studentClassSchedule($request);
    }

        private function formatStaffScheduleResponse($staff, $nextClass, $todayClasses, $weekSchedule, $upcomingSessions, $allSessions = null)
    {
        $isAdminOrStaff = $staff && in_array($staff->role, ['admin', 'advisor', 'tutor']);
        
        $transformSession = function ($session) use ($staff, $isAdminOrStaff) {
            if (!$session) return null;
            
            $class = $session->class;
            if ($class) {
                if ($class->subject_id) {
                    $enrolled = $this->getEnrolledStudentsForSubject($class->subject_id);
                    $session->enrolled_students = $enrolled;
                    $session->enrolled_count = $enrolled->count();
                    $class->enrolled_students = $enrolled;
                    $class->enrolled_count = $enrolled->count();
                }

                if ($class->zoom_start_url && $isAdminOrStaff) {
                    $session->class_link = $class->zoom_start_url;
                }
            }
            return $session;
        };

        if ($nextClass) {
            $nextClass = $transformSession($nextClass);
        }

        if ($todayClasses) {
            $todayClasses = $todayClasses->map($transformSession);
        }
        
        if ($upcomingSessions) {
            $upcomingSessions = $upcomingSessions->map($transformSession);
        }

        if ($weekSchedule) {
            $weekSchedule = $weekSchedule->map(function ($sessions) use ($transformSession) {
                return $sessions->map($transformSession);
            });
        }

        $sessions = null;
        if ($allSessions) {
            $sessions = $allSessions->map($transformSession);
        }

        return [
            'next_class' => $nextClass,
            'today_classes' => $todayClasses,
            'week_schedule' => $weekSchedule,
            'upcoming_sessions' => $upcomingSessions,
            'sessions' => $sessions ?? $upcomingSessions,
        ];
    }
}
