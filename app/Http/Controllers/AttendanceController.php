<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassAttendance;
use App\Models\ClassSession;
use App\Services\StudentNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    /**
     * Student: Join Live Masterclass & Record Initial Attendance.
     * Validates that the current server time is within the allocated class window.
     */
    public function joinAttendance(Request $request)
    {
        $validated = $request->validate([
            'class_session_id' => 'required|exists:class_sessions,id',
        ]);

        $student = $request->user();
        $sessionId = (int) $validated['class_session_id'];

        $session = ClassSession::with(['class.subject', 'class.staffs'])->find($sessionId);
        if (!$session) {
            return response()->json(['message' => 'Class session not found.'], 404);
        }

        // Server-Side Class Window Validation
        $sessionDateStr = $session->session_date ? $session->session_date->toDateString() : today()->toDateString();
        $startTimeStr = $session->starts_at ?: '00:00:00';
        $endTimeStr = $session->ends_at ?: '23:59:59';

        $scheduledStart = Carbon::parse("{$sessionDateStr} {$startTimeStr}");
        $scheduledEnd = Carbon::parse("{$sessionDateStr} {$endTimeStr}");

        $openWindowStart = $scheduledStart->copy()->subMinutes(15);
        $closeWindowEnd = $scheduledEnd->copy()->addMinutes(30);

        $now = now();

        // If class time has expired or not opened yet, reject attendance initiation
        if ($now->lt($openWindowStart)) {
            return response()->json([
                'success' => false,
                'message' => "Class attendance opens 15 minutes before scheduled start time ({$scheduledStart->format('h:i A')}).",
                'is_open' => false,
            ], 422);
        }

        if ($now->gt($closeWindowEnd)) {
            return response()->json([
                'success' => false,
                'message' => "The allocated time for this class session has ended. Attendance is closed.",
                'is_open' => false,
            ], 422);
        }

        // Multi-join handling: find existing or create initial attendance record
        $attendance = ClassAttendance::where('class_session_id', $sessionId)
            ->where('student_id', $student->id)
            ->first();

        $isLate = $now->gt($scheduledStart->copy()->addMinutes(15));
        $status = $isLate ? 'late' : 'present';

        if (!$attendance) {
            $attendance = ClassAttendance::create([
                'class_session_id' => $sessionId,
                'student_id' => $student->id,
                'joined_at' => $now,
                'left_at' => $now,
                'status' => $status,
            ]);

// Silent attendance tracking (post-class reports only)
        } else {
            // Student re-joining: keep original joined_at and update left_at timestamp
            $attendance->update([
                'left_at' => $now,
                'status' => $attendance->status === 'absent' ? $status : $attendance->status,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Class attendance active.',
            'attendance_id' => $attendance->id,
            'status' => $attendance->status,
            'joined_at' => $attendance->joined_at ? $attendance->joined_at->toISOString() : null,
        ], 200);
    }

    /**
     * Student: Periodic Heartbeat Ping (every 2-3 mins) while in Live Zoom meeting.
     */
    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'class_session_id' => 'required|exists:class_sessions,id',
        ]);

        $student = $request->user();
        $sessionId = (int) $validated['class_session_id'];

        $attendance = ClassAttendance::where('class_session_id', $sessionId)
            ->where('student_id', $student->id)
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'No active attendance record found.'], 404);
        }

        $now = now();
        $attendance->update([
            'left_at' => $now,
        ]);

        $durationMinutes = $attendance->joined_at ? max(1, (int) $attendance->joined_at->diffInMinutes($now)) : 1;

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat acknowledged.',
            'active_duration_minutes' => $durationMinutes,
            'last_seen' => $now->toISOString(),
        ], 200);
    }

    /**
     * Student: Leave Meeting / End Attendance Session.
     */
    public function leaveAttendance(Request $request)
    {
        $validated = $request->validate([
            'class_session_id' => 'required|exists:class_sessions,id',
        ]);

        $student = $request->user();
        $sessionId = (int) $validated['class_session_id'];

        $attendance = ClassAttendance::where('class_session_id', $sessionId)
            ->where('student_id', $student->id)
            ->first();

        if ($attendance) {
            $now = now();
            $attendance->update([
                'left_at' => $now,
            ]);

            $durationMinutes = $attendance->joined_at ? max(1, (int) $attendance->joined_at->diffInMinutes($now)) : 1;

            return response()->json([
                'success' => true,
                'message' => 'Class session attendance finalized.',
                'total_minutes' => $durationMinutes,
            ], 200);
        }

        return response()->json(['success' => false, 'message' => 'Attendance record not found.'], 404);
    }
}