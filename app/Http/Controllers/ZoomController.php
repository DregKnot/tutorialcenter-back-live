<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\ClassStaff;
use App\Models\Classes;
use App\Models\CourseEnrollment;
use App\Services\ZoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ZoomController extends Controller
{
    protected ZoomService $zoomService;

    public function __construct(ZoomService $zoomService)
    {
        $this->zoomService = $zoomService;
    }

    /**
     * Parse Zoom meeting number and password from a Zoom URL.
     */
    private function parseZoomUrl(string $url): ?array
    {
        if (preg_match('/zoom\.(?:us|com)\/(?:j|s|wc\/join)\/(\d+)/i', $url, $matches)) {
            $meetingId = $matches[1];
            $parsedUrl = parse_url($url);
            $password = null;
            if (!empty($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                $password = $queryParams['pwd'] ?? null;
            }
            return [
                'meeting_id' => $meetingId,
                'password' => $password,
            ];
        }
        return null;
    }

    public function generateSignature(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_session_id' => 'required|exists:class_sessions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $session = ClassSession::findOrFail($request->class_session_id);
            $class = $session->class;

            // Enforce active subscription check for students joining masterclasses
            $user = $request->user();
            if ($user instanceof \App\Models\Student) {
                $now = now();
                $hasActiveSubscription = \App\Models\CoursesEnrollment::where('student_id', $user->id)
                    ->where('status', 'active')
                    ->where(function ($q) use ($now) {
                        $q->whereNull('end_date')
                          ->orWhere('end_date', '>=', $now->copy()->subMinutes(5));
                    })
                    ->whereHas('payments', function ($q) {
                        $q->whereIn('status', ['successful', 'paid']);
                    })
                    ->exists();

                if (!$hasActiveSubscription) {
                    return response()->json([
                        'success' => false,
                        'subscription_expired' => true,
                        'message' => 'Your training subscription has expired. Please renew your course to join live masterclasses.',
                    ], 403);
                }
            }

            if (!$class) {
                return response()->json([
                    'success' => false,
                    'message' => 'Class not found for this session.',
                ], 404);
            }

            // 1. Resolve raw meeting link if present
            $rawMeetingLink = $session->class_link 
                ?: $class->zoom_join_url 
                ?: $class->zoom_start_url 
                ?: $class->class_link;

            // 2. If the class uses Google Meet, respond with direct meet redirect payload
            if ($rawMeetingLink && str_contains(strtolower($rawMeetingLink), 'meet.google.com')) {
                return response()->json([
                    'success' => true,
                    'provider' => 'google_meet',
                    'meeting_type' => 'google_meet',
                    'meeting_url' => $rawMeetingLink,
                    'message' => 'Google Meet classroom link resolved.',
                ], 200);
            }

            // 3. If zoom_meeting_id is missing on the class, attempt auto-extraction from links
            if (empty($class->zoom_meeting_id) && !empty($rawMeetingLink)) {
                $parsed = $this->parseZoomUrl($rawMeetingLink);
                if ($parsed) {
                    $class->update([
                        'zoom_meeting_id' => $parsed['meeting_id'],
                        'zoom_meeting_password' => $parsed['password'] ?: $class->zoom_meeting_password,
                        'zoom_join_url' => $class->zoom_join_url ?: $rawMeetingLink,
                    ]);
                    $class->zoom_meeting_id = $parsed['meeting_id'];
                    $class->zoom_meeting_password = $parsed['password'] ?: $class->zoom_meeting_password;
                }
            }

            // 4. Fallback: try creating Zoom meeting via ZoomService if still missing
            if (empty($class->zoom_meeting_id)) {
                try {
                    $created = $this->zoomService->createMeeting($class->title ?: 'Masterclass Session');
                    if (!empty($created['id'])) {
                        $class->update([
                            'zoom_meeting_id' => (string) $created['id'],
                            'zoom_meeting_password' => $created['password'] ?? null,
                            'zoom_join_url' => $created['join_url'] ?? null,
                            'zoom_start_url' => $created['start_url'] ?? null,
                        ]);
                        $class->zoom_meeting_id = (string) $created['id'];
                        $class->zoom_meeting_password = $created['password'] ?? null;
                    }
                } catch (\Throwable $zoomEx) {
                    // Log or handle Zoom API creation error
                }
            }

            if (empty($class->zoom_meeting_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This class session does not have a Zoom meeting configured.',
                ], 400);
            }

            $user = auth('sanctum')->user() ?: auth('staff')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.',
                ], 401);
            }

            $role = 0;

            if ($user instanceof \App\Models\Student) {
                $isEnrolled = $user->enrolledInSubject($class->subject_id)
                    || CourseEnrollment::where('student_id', $user->id)
                        ->where('status', 'active')
                        ->whereHas('course.subjects', fn($q) => $q->where('subjects.id', $class->subject_id))
                        ->exists();

                if (!$isEnrolled) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not enrolled in this subject.',
                    ], 403);
                }
                $role = 0; // Student joins as participant
            } elseif ($user instanceof \App\Models\Staff) {
                $roleName = strtolower(trim($user->role ?? ''));
                // Authorized host roles: Advisors, Admins, and COO initiate and govern the class as Host
                $isHostRole = in_array($roleName, ['admin', 'advisor', 'courseadvisor', 'course_advisor', 'coo']);
                
                $isAssigned = $class->staffs()->where('staffs.id', $user->id)->exists()
                    || ClassStaff::where('class_id', $class->id)->where('staff_id', $user->id)->exists();

                if (!$isHostRole && !$isAssigned) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Not authorized to access this class.',
                    ], 403);
                }

                $pivotRole = ClassStaff::where('class_id', $class->id)
                    ->where('staff_id', $user->id)
                    ->value('role');
                $pivotRole = $pivotRole ? strtolower($pivotRole) : null;

                // Class Initiation & Waiting Room Bypass Governance:
                // 1. PHASE 1 (Class Not Yet Started): ONLY a Course Advisor can start the meeting (role = 1).
                //    Tutors, Admins, and Students get role = 0 and are blocked by Zoom's join_before_host = false.
                // 2. PHASE 2 (Class Is Running): The original Advisor retains Primary Host (role = 1).
                //    Admins, COO, and ANY other Course Advisor join with role = 1 (elevated Co-Host),
                //    bypassing the Waiting Room completely so they can drop in and co-host/audit without being let in.
                // 3. Tutors and Students strictly enter as participants (role = 0) and are held in the Waiting Room.
                $isAdvisor = in_array($roleName, ['advisor', 'courseadvisor', 'course_advisor']);
                $isAdminOrCoo = in_array($roleName, ['admin', 'coo']);
                $isPrivilegedStaff = $isAdvisor || $isAdminOrCoo;

                $role = 0;

                \Illuminate\Support\Facades\DB::transaction(function () use ($session, $user, $isAdvisor, $isPrivilegedStaff, &$role) {
                    $lockedSession = ClassSession::where('id', $session->id)->lockForUpdate()->first();

                    // Check if session has a valid active host within the last 6 hours
                    $hasActiveHost = !empty($lockedSession->started_by_staff_id)
                        && !empty($lockedSession->started_at)
                        && \Carbon\Carbon::parse($lockedSession->started_at)->gt(now()->subHours(6));

                    if (!$hasActiveHost) {
                        // PHASE 1: CLASS NOT YET STARTED
                        // Only a Course Advisor can initiate and start the meeting
                        if ($isAdvisor) {
                            $lockedSession->update([
                                'started_by_staff_id' => $user->id,
                                'started_at' => now(),
                            ]);
                            $session->started_by_staff_id = $user->id;
                            $session->started_at = now();
                            $role = 1; // Primary Host
                        } else {
                            // Tutors, Admins, COO, and Students cannot start early
                            $role = 0;
                        }
                    } else {
                        // PHASE 2: CLASS IS ACTIVELY RUNNING
                        if ((int)$lockedSession->started_by_staff_id === (int)$user->id) {
                            // The primary hosting advisor reconnecting: preserve Host role
                            $role = 1;
                        } elseif ($isPrivilegedStaff) {
                            // Admins, COO, and OTHER Course Advisors join as elevated Co-Hosts (role = 1),
                            // bypassing the Waiting Room directly with no disruption to the primary host.
                            $role = 1;
                        } else {
                            // Tutors, moderators, assistants, and students strictly enter as participants (role = 0)
                            // and are held in the Waiting Room until admitted by the Advisor.
                            $role = 0;
                        }
                    }
                });
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized user type.',
                ], 401);
            }

            $signature = $this->zoomService->generateSignature(
                $class->zoom_meeting_id,
                $role
            );

            return response()->json([
                'success' => true,
                'signature' => $signature,
                'meeting_number' => $class->zoom_meeting_id,
                'password' => $class->zoom_meeting_password,
                'join_url' => $class->zoom_join_url ?: $rawMeetingLink,
                'zoom_join_url' => $class->zoom_join_url ?: $rawMeetingLink,
                'sdk_key' => config('services.zoom.sdk_key'),
                'role' => $role,
                'user_name' => $user->firstname . ' ' . $user->surname,
                'user_email' => $user->email,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Zoom signature.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
