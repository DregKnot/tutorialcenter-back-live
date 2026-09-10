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

                // Host power and Zoom Pro Plan single-host protection:
                // 1. Course Advisors are the dedicated primary hosts who initiate and govern classes (role = 1).
                // 2. Admins & COO can initiate as Host (role = 1), or join as executive auditors (role = 0) to prevent kicking out the Advisor.
                // 3. Tutors, teachers, and moderators strictly join as participants (role = 0) to teach without host collision.
                if (in_array($roleName, ['advisor', 'courseadvisor', 'course_advisor'])) {
                    $role = 1;
                } elseif (in_array($roleName, ['admin', 'coo'])) {
                    $wantsAuditor = $request->boolean('auditor') || $request->input('role') === '0';
                    $role = $wantsAuditor ? 0 : 1;
                } else {
                    $role = 0;
                }
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
                'sdk_key' => config('services.zoom.sdk_key'),
                'role' => $role,
                'user_name' => $user->firstname . ' ' . $user->surname,
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
