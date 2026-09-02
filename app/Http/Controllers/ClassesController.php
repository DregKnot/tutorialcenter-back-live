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

    /**
     * (admin) create a new class
    **/
    public function store(Request $request, ZoomService $zoomService){
        $validator = Validator::make($request->all(), [

            'subject_id' => 'required|exists:subjects,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',

            'staffs' => 'nullable|array',
            'staffs.*.staff_id' => 'required_with:staffs|exists:staffs,id',
            'staffs.*.role' => 'nullable|string|max:100',

            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            
            'class_link' => 'nullable|url',

            'schedules' => 'required|array|min:1',

            'schedules.*.day_of_week' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.duration_minutes' => 'required|integer|min:1',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 1. Generate Class Title If Missing
            |--------------------------------------------------------------------------
            */

            if (empty($request->title)) {

                $subject = Subject::find($request->subject_id);
                $course = $subject ? Course::find($subject->course_id[0] ?? null) : null;

                $title = 'Untitled Class';

                if ($subject && $course) {
                    $title = $course->title . ' ' . $subject->name . ' Class';
                } elseif ($subject) {
                    $title = $subject->name . ' Class';
                }

                $request->merge(['title' => $title]);
            }

            // Prevent duplicate classes, and generate a Zoom meeting if no custom link is provided
            $class = Classes::where('subject_id', $request->subject_id)
                ->where('title', $request->title)
                ->first();

            $classLink = $request->class_link;

            if (!$class) {
                $zoomMeetingId = null;
                $zoomMeetingPassword = null;
                $zoomJoinUrl = null;
                $zoomStartUrl = null;

                if (empty($classLink)) {
                    $subject = Subject::find($request->subject_id);
                    $topic = ($subject ? $subject->name : 'Class') . ': ' . $request->title;

                    $zoomMeeting = $zoomService->createMeeting($topic);

                    $zoomMeetingId = (string) $zoomMeeting['id'];
                    $zoomMeetingPassword = $zoomMeeting['password'] ?? null;
                    $zoomJoinUrl = $zoomMeeting['join_url'];
                    $zoomStartUrl = $zoomMeeting['start_url'] ?? null;

                    $classLink = $zoomJoinUrl;
                }

                $class = Classes::create([
                    'subject_id' => $request->subject_id,
                    'title' => $request->title,
                    'description' => $request->description,
                    'status' => $request->status,
                    'zoom_meeting_id' => $zoomMeetingId,
                    'zoom_meeting_password' => $zoomMeetingPassword,
                    'zoom_join_url' => $zoomJoinUrl,
                    'zoom_start_url' => $zoomStartUrl,
                ]);
            } else {
                if (empty($classLink)) {
                    $classLink = $class->zoom_join_url ?? $request->class_link;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Assign Staff (Avoid Duplicate Pivot)
            |--------------------------------------------------------------------------
            */

            if ($request->has('staffs')) {

                $staffData = [];

                foreach ($request->staffs as $staff) {

                    $staffData[$staff['staff_id']] = [
                        'role' => $staff['role'] ?? null
                    ];
                }

                $class->staffs()->syncWithoutDetaching($staffData);
            }

            /*
            |--------------------------------------------------------------------------
            | 4. Create Schedules + Sessions
            |--------------------------------------------------------------------------
            */

            $sessionsCreated = 0;

            foreach ($request->schedules as $scheduleData) {

                $endTime = Carbon::createFromFormat('H:i', $scheduleData['start_time'])
                    ->addMinutes($scheduleData['duration_minutes'])
                    ->format('H:i');

                /*
                |--------------------------------------------------------------------------
                | Prevent Duplicate Schedule
                |--------------------------------------------------------------------------
                */

                $schedule = ClassSchedule::firstOrCreate(
                    [
                        'class_id' => $class->id,
                        'day_of_week' => $scheduleData['day_of_week'],
                        'start_time' => $scheduleData['start_time']
                    ],
                    [
                        'end_time' => $endTime,
                        'start_date' => $request->start_date,
                        'end_date' => $request->end_date
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Generate Weekly Sessions
                |--------------------------------------------------------------------------
                */

                $startDate = Carbon::parse($request->start_date)->startOfDay();
                $endDate = Carbon::parse($request->end_date)->endOfDay();
                $targetDay = strtolower(trim($scheduleData['day_of_week']));

                $current = $startDate->copy();
                if (strtolower($current->format('l')) !== $targetDay) {
                    $current->next($targetDay);
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
                                'starts_at' => $scheduleData['start_time'],
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

            DB::commit();

            return response()->json([
                'message' => 'Class created successfully',
                'sessions_created' => $sessionsCreated,
                'class' => $class->load([
                    'subject',
                    'staffs',
                    'schedules',
                    'sessions'
                ])
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Class creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * (student) Get student schedule with basic session info
    **/
    public function studentCalenderSchedule(Request $request){
        $student = $request->user();

        $activeEnrollments = $student->courseEnrollments()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now()->subDays(1));
            })
            ->whereHas('payments', function ($query) {
                $query->whereIn('status', ['successful', 'paid']);
            })
            ->pluck('id');

        if ($activeEnrollments->isEmpty()) {
            return response()->json([
                'message' => 'No active courses found',
                'sessions' => []
            ]);
        }

        $subjectIds = $student->subjectEnrollments()
            ->whereIn('course_enrollment_id', $activeEnrollments)
            ->pluck('subject_id');

        if ($subjectIds->isEmpty()) {
            return response()->json([
                'message' => 'No subjects registered',
                'sessions' => []
            ]);
        }

        $sessions = ClassSession::with([
            'class.subject',
            'class.staffs'
        ])
            ->whereHas('class', function ($query) use ($subjectIds) {
                $query->whereIn('subject_id', $subjectIds)
                    ->where('status', 'active');
            })
            ->orderBy('session_date')
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'sessions' => $sessions
        ]);
    }

    /**
     * (student) Get student schedule with attendance status
    **/
    public function studentClassSchedule(Request $request){
        try {
            $student = $request->user();
    
            $activeEnrollments = $student->courseEnrollments()
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', now()->subDays(1));
                })
                ->whereHas('payments', function ($q) {
                    $q->whereIn('status', ['successful', 'paid']);
                })
                ->pluck('id');
    
            if ($activeEnrollments->isEmpty()) {
                return response()->json([
                    'next_class' => null,
                    'today_classes' => [],
                    'week_schedule' => [],
                    'upcoming_sessions' => [],
                    'older_sessions' => [],
                    'sessions' => [],
                    'attendance' => [],
                ]);
            }
    
            $subjectIds = $student->subjectEnrollments()
                ->whereIn('course_enrollment_id', $activeEnrollments)
                ->pluck('subject_id');
    
            $sessionQuery = ClassSession::with([
                'class.subject',
                'class.staffs'
            ])->whereHas('class', function ($q) use ($subjectIds) {
                $q->whereIn('subject_id', $subjectIds)->where('status', 'active');
            });

            $allSessions = (clone $sessionQuery)
                ->orderBy('session_date', 'asc')
                ->orderBy('starts_at', 'asc')
                ->get();
    
            $nextClass = (clone $sessionQuery)
                ->whereDate('session_date', '>=', today())
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
                ->whereDate('session_date', '>=', today())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->limit(100)
                ->get();

            $olderSessions = (clone $sessionQuery)
                ->whereDate('session_date', '<', today())
                ->orderBy('session_date', 'desc')
                ->orderBy('starts_at', 'desc')
                ->limit(100)
                ->get();
    
            $attendance = ClassAttendance::where('student_id', $student->id)->pluck('status', 'class_session_id');

            return response()->json([
                'next_class' => $nextClass,
                'today_classes' => $todayClasses,
                'week_schedule' => $weekSchedule,
                'upcoming_sessions' => $upcomingSessions,
                'older_sessions' => $olderSessions,
                'sessions' => $allSessions,
                'attendance' => $attendance,
            ]);
            
            /*
            return response()->json([
                'next_class' => $nextClass ? array_merge($nextClass->toArray(), ['attendance_status' => $attendance[$nextClass->id] ?? 'not_marked']) : null,
                'today_classes' => $todayClasses->map(fn($session) => array_merge($session->toArray(), ['attendance_status' => $attendance[$session->id] ?? 'not_marked'])),
                'week_schedule' => $weekSchedule->map(fn($sessions) => $sessions->map(fn($session) => array_merge($session->toArray(), ['attendance_status' => $attendance[$session->id] ?? 'not_marked']))),
                'upcoming_sessions' => $upcomingSessions->map(fn($session) => array_merge($session->toArray(), ['attendance_status' => $attendance[$session->id] ?? 'not_marked'])),
            ]);
            */
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch schedule',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

    }


    /**
     * (tutor) Get tutor schedule with attendance status
    **/
    public function tutorClassesSchedule(Request $request){
        try {
            $staff = $request->user();
    
            /*
            |--------------------------------------------------------------------------
            | 1. Get Classes Assigned to Staff
            |--------------------------------------------------------------------------
            */
    
            $classIds = ClassStaff::where('staff_id', $staff->id)
                ->pluck('class_id');
    
            if ($classIds->isEmpty()) {
                return response()->json([
                    'next_class' => null,
                    'today_classes' => [],
                    'week_schedule' => [],
                    'upcoming_sessions' => []
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | 2. Base Session Query
            |--------------------------------------------------------------------------
            */
    
            $sessionQuery = ClassSession::with([
                'class.subject',
                'class.staffs'
            ])
            ->whereIn('class_id', $classIds)
            ->whereHas('class', function ($q) {
                $q->where('status', 'active');
            });
    
            /*
            |--------------------------------------------------------------------------
            | 3. Next Class
            |--------------------------------------------------------------------------
            */
    
            $nextClass = (clone $sessionQuery)
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->first();
    
            /*
            |--------------------------------------------------------------------------
            | 4. Today's Classes
            |--------------------------------------------------------------------------
            */
    
            $todayClasses = (clone $sessionQuery)
                ->whereDate('session_date', today())
                ->orderBy('starts_at')
                ->get();
    
            /*
            |--------------------------------------------------------------------------
            | 5. Weekly Schedule
            |--------------------------------------------------------------------------
            */
    
            $weekSchedule = (clone $sessionQuery)
                ->whereBetween('session_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->get()
                ->groupBy('session_date');
    
            /*
            |--------------------------------------------------------------------------
            | 6. Upcoming Sessions
            |--------------------------------------------------------------------------
            */
    
            $upcomingSessions = (clone $sessionQuery)
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->limit(200)
                ->get();

            $allSessions = (clone $sessionQuery)
                ->orderBy('session_date', 'asc')
                ->orderBy('starts_at', 'asc')
                ->get();
    
            $formattedResponse = $this->formatStaffScheduleResponse(
                $staff,
                $nextClass,
                $todayClasses,
                $weekSchedule,
                $upcomingSessions,
                $allSessions
            );
            return response()->json($formattedResponse);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch tutor schedule',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * (advisor) Get advisor schedule with advisees' classes
    **/
    public function advisorClassesSchedule(Request $request){
        try {
            $staff = $request->user();
    
            /*
            |--------------------------------------------------------------------------
            | 1. Get Classes Assigned to Staff
            |--------------------------------------------------------------------------
            */
    
            $classIds = ClassStaff::where('staff_id', $staff->id)
                ->pluck('class_id');
    
            if ($classIds->isEmpty()) {
                return response()->json([
                    'next_class' => null,
                    'today_classes' => [],
                    'week_schedule' => [],
                    'upcoming_sessions' => []
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | 2. Base Session Query
            |--------------------------------------------------------------------------
            */
    
            $sessionQuery = ClassSession::with([
                'class.subject',
                'class.staffs'
            ])
            ->whereIn('class_id', $classIds)
            ->whereHas('class', function ($q) {
                $q->where('status', 'active');
            });
    
            /*
            |--------------------------------------------------------------------------
            | 3. Next Class
            |--------------------------------------------------------------------------
            */
    
            $nextClass = (clone $sessionQuery)
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->first();
    
            /*
            |--------------------------------------------------------------------------
            | 4. Today's Classes
            |--------------------------------------------------------------------------
            */
    
            $todayClasses = (clone $sessionQuery)
                ->whereDate('session_date', today())
                ->orderBy('starts_at')
                ->get();
    
            /*
            |--------------------------------------------------------------------------
            | 5. Weekly Schedule
            |--------------------------------------------------------------------------
            */
    
            $weekSchedule = (clone $sessionQuery)
                ->whereBetween('session_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->get()
                ->groupBy('session_date');
    
            /*
            |--------------------------------------------------------------------------
            | 6. Upcoming Sessions
            |--------------------------------------------------------------------------
            */
    
            $upcomingSessions = (clone $sessionQuery)
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->limit(200)
                ->get();

            $allSessions = (clone $sessionQuery)
                ->orderBy('session_date', 'asc')
                ->orderBy('starts_at', 'asc')
                ->get();
    
            $formattedResponse = $this->formatStaffScheduleResponse(
                $staff,
                $nextClass,
                $todayClasses,
                $weekSchedule,
                $upcomingSessions,
                $allSessions
            );
            return response()->json($formattedResponse);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch tutor schedule',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * (admin) Get classes schdule for all subjects 
    **/
    public function allClassesSchedule(Request $request){
        try {
            $classes = Classes::with(['subject', 'staffs', 'schedules.sessions'])
                ->whereHas('subject', fn($q) => $q->where('status', 'active'))
                ->where('status', 'active')
                ->get();

            return response()->json([
                'classes' => $classes
            ]);

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

    private function formatStaffScheduleResponse($staff, $nextClass, $todayClasses, $weekSchedule, $upcomingSessions, $allSessions = null)
    {
        $isAdmin = $staff->role === 'admin';
        
        $transformSession = function ($session) use ($staff, $isAdmin) {
            if (!$session) return null;
            
            $class = $session->class;
            if ($class && $class->zoom_start_url) {
                // Determine if this staff member is an advisor/admin for this class
                $classStaff = ClassStaff::where('class_id', $class->id)
                    ->where('staff_id', $staff->id)
                    ->first();
                
                $isAdvisor = $classStaff && strtolower($classStaff->role) === 'advisor';
                
                if ($isAdmin || $isAdvisor) {
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