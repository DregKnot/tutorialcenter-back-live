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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\ZoomService;

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
     * (admin) Get classes schdule for all subjects 
    **/
        public function allClassesSchedule(Request $request){
        try {
            $staff = $request->user() ?: auth('staff')->user();

            $classes = Classes::with(['subject', 'staffs', 'schedules.sessions.attendances.student'])
                ->whereHas('subject', fn($q) => $q->where('status', 'active'))
                ->where('status', 'active')
                ->get();

            $classes->each(function ($class) {
                $enrolledStudents = $this->getEnrolledStudentsForSubject($class->subject_id);
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
            
            // Base query for class sessions that are past and have a recording link
            $query = ClassSession::with(['class.staffs', 'class.subject'])
                ->whereNotNull('recording_link')
                ->where('recording_link', '!=', '')
                ->where('ends_at', '<', now());

            // If it's a student, filter classes they are enrolled in
            if ($user && method_exists($user, 'classes')) {
                // Assuming students belong to classes
                // If they don't, we might just return all for now or filter by student's course
            }

            $sessions = $query->orderBy('starts_at', 'desc')->get();

            $recordedClasses = $sessions->map(function ($session) {
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

                return [
                    'id' => $session->id,
                    'title' => $formattedTitle,
                    'topic' => $topic,
                    'subject' => $subject,
                    'tutor' => $tutorName,
                    'date' => \Carbon\Carbon::parse($session->starts_at)->format('M j, Y'),
                    'duration' => $duration,
                    'videoUrl' => $url,
                    'videoId' => $videoId,
                    'color' => 'from-blue-600 to-indigo-600' // Default color for UI
                ];
            });

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

    
    /**
     * (admin) update an existing class, its assigned staff, and schedule/sessions
    **/
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
            }

            if (!empty($updateData)) {
                $class->update($updateData);
            }

            // Sync assigned staff
            if ($request->has('staffs')) {
                $staffData = [];
                foreach ($request->staffs as $staff) {
                    $staffData[$staff['staff_id']] = [
                        'role' => $staff['role'] ?? 'tutor'
                    ];
                }
                $class->staffs()->sync($staffData);
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
        return $this->allClassesSchedule($request);
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
                    $leadStaff = $class->staffs->first(fn($s) => $s->role === 'lead') ?: $class->staffs->first();
                    $tutor = $leadStaff->staff ?: $leadStaff;
                }

                $myAttendance = $session->attendances ? $session->attendances->first() : null;

                return [
                    'id' => $session->id,
                    'class_id' => $session->class_id,
                    'title' => $session->title ?: ($class ? $class->title : 'Master Class'),
                    'topic' => $session->title ?: ($class ? $class->title : 'Master Class'),
                    'subject_name' => $class && $class->subject ? $class->subject->name : 'General',
                    'subject' => $class ? $class->subject : null,
                    'session_date' => $session->session_date ? \Carbon\Carbon::parse($session->session_date)->toDateString() : null,
                    'starts_at' => $session->starts_at ? substr($session->starts_at, 0, 5) : '10:00',
                    'ends_at' => $session->ends_at ? substr($session->ends_at, 0, 5) : '11:30',
                    'class_link' => $session->class_link ?: ($class ? ($class->zoom_join_url ?: $class->class_link) : null),
                    'recording_link' => $session->recording_link,
                    'tutor' => $tutor ? [
                        'id' => $tutor->id,
                        'name' => trim(($tutor->firstname ?? '') . ' ' . ($tutor->surname ?? '')),
                        'email' => $tutor->email ?? '',
                        'avatar' => $tutor->profile_picture ?? null,
                    ] : [
                        'name' => 'Tutorial Center Tutor',
                    ],
                    'tutor_name' => $tutor ? trim(($tutor->firstname ?? '') . ' ' . ($tutor->surname ?? '')) : 'Tutorial Center Tutor',
                    'status' => $session->status ?? 'scheduled',
                    'my_attendance' => $myAttendance ? [
                        'status' => $myAttendance->status,
                        'joined_at' => $myAttendance->joined_at,
                    ] : null,
                ];
            };

            $nextClassRaw = (clone $sessionQuery)
                ->whereDate('session_date', '>=', today())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->first();

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
                'next_class' => $transformStudentSession($nextClassRaw),
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