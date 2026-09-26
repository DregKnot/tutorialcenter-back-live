<?php

namespace App\Http\Controllers;

use App\Models\ClassAttendance;
use App\Models\ClassSession;
use App\Models\Student;
use App\Services\StudentNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * Legacy / Direct attendance store alias
     */
    public function store(Request $request)
    {
        return $this->joinAttendance($request);
    }

    /**
     * Student: Join Live Masterclass & Record Initial Attendance.
     * Records or updates student presence without rigid window rejection.
     */
    public function joinAttendance(Request $request)
    {
        $validated = $request->validate([
            'class_session_id' => 'required|exists:class_sessions,id',
        ]);

        $student = $request->user() ?: auth('student')->user();
        if (! $student) {
            return response()->json(['message' => 'Unauthorized student.'], 401);
        }

        $sessionId = (int) $validated['class_session_id'];

        $session = ClassSession::with(['class.subject', 'class.staffs'])->find($sessionId);
        if (! $session) {
            return response()->json(['message' => 'Class session not found.'], 404);
        }

        // Class Session Timing Calculation
        $sessionDateStr = $session->session_date ? $session->session_date->toDateString() : today()->toDateString();
        $startTimeStr = $session->starts_at ?: '00:00:00';
        $endTimeStr = $session->ends_at ?: '23:59:59';

        $scheduledStart = Carbon::parse("{$sessionDateStr} {$startTimeStr}");
        $scheduledEnd = Carbon::parse("{$sessionDateStr} {$endTimeStr}");
        if ($scheduledEnd->lt($scheduledStart)) {
            $scheduledEnd->addDay();
        }

        $openWindowStart = $scheduledStart->copy()->subMinutes(15);
        $closeWindowEnd = $scheduledEnd->copy()->addMinutes(30);

        $now = now();
        $isOpen = $now->between($openWindowStart, $closeWindowEnd);

        $isLate = $now->gt($scheduledStart->copy()->addMinutes(15));
        $status = $isLate ? 'late' : 'present';

        if (StudentNotificationService::enabled()) {
            $attendance = DB::transaction(function () use ($student, $sessionId, $scheduledStart, $now, $status) {
                Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
                $attendance = ClassAttendance::where('class_session_id', $sessionId)
                    ->where('student_id', $student->id)->lockForUpdate()->first();

                if (! $attendance) {
                    $attendance = ClassAttendance::create([
                        'class_session_id' => $sessionId,
                        'student_id' => $student->id,
                        'joined_at' => $now,
                        'left_at' => $now,
                        'status' => $status,
                    ]);
                }
                $attendance->closeStaleVisit();
                if ($attendance->connection_state !== 'active') {
                    if ($attendance->status === 'absent') {
                        $attendance->update(['status' => $status]);
                    }
                    $attendance->beginVisit();
                } else {
                    $attendance->observeConnection();
                }

                return $attendance;
            });
        } else {
            // Multi-join handling: find existing or create initial attendance record
            $attendance = ClassAttendance::where('class_session_id', $sessionId)
                ->where('student_id', $student->id)
                ->first();

            if (! $attendance) {
                $attendance = ClassAttendance::create([
                    'class_session_id' => $sessionId,
                    'student_id' => $student->id,
                    'joined_at' => $now,
                    'left_at' => $now,
                    'status' => $status,
                ]);
            } else {
                $attendance->update([
                    'left_at' => $now,
                    'status' => $attendance->status === 'absent' ? $status : $attendance->status,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Class attendance active.',
            'attendance_id' => $attendance->id,
            'status' => $attendance->status,
            'is_open' => $isOpen,
            'joined_at' => $attendance->joined_at ? $attendance->joined_at->toISOString() : null,
        ], 200);
    }

    /**
     * Student: Periodic Heartbeat Ping (every 2-3 mins) while in Live Zoom meeting.
     * Auto-initializes attendance if join was missed so heartbeat never throws 404.
     */
    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'class_session_id' => 'required|exists:class_sessions,id',
            'seconds' => 'nullable|integer',
        ]);

        $student = $request->user() ?: auth('student')->user();
        if (! $student) {
            return response()->json(['message' => 'Unauthorized student.'], 401);
        }

        $sessionId = (int) $validated['class_session_id'];

        $session = ClassSession::find($sessionId);
        if (! $session) {
            return response()->json(['message' => 'Class session not found.'], 404);
        }

        $now = now();

        $attendance = ClassAttendance::where('class_session_id', $sessionId)
            ->where('student_id', $student->id)
            ->first();

        // If no attendance record exists, self-heal and create initial attendance
        if (! $attendance) {
            $sessionDateStr = $session->session_date ? $session->session_date->toDateString() : today()->toDateString();
            $startTimeStr = $session->starts_at ?: '00:00:00';
            $scheduledStart = Carbon::parse("{$sessionDateStr} {$startTimeStr}");
            $isLate = $now->gt($scheduledStart->copy()->addMinutes(15));
            $status = $isLate ? 'late' : 'present';

            if (StudentNotificationService::enabled()) {
                $attendance = DB::transaction(function () use ($student, $sessionId, $now, $status) {
                    $attendance = ClassAttendance::create([
                        'class_session_id' => $sessionId,
                        'student_id' => $student->id,
                        'joined_at' => $now,
                        'left_at' => $now,
                        'status' => $status,
                    ]);
                    $attendance->beginVisit();
                    return $attendance;
                });
            } else {
                $attendance = ClassAttendance::create([
                    'class_session_id' => $sessionId,
                    'student_id' => $student->id,
                    'joined_at' => $now,
                    'left_at' => $now,
                    'status' => $status,
                ]);
            }
        } else {
            // Existing attendance: process heartbeat ping
            if (StudentNotificationService::enabled()) {
                $attendance = DB::transaction(function () use ($attendance) {
                    $attendance = ClassAttendance::whereKey($attendance->id)->lockForUpdate()->firstOrFail();
                    $attendance->closeStaleVisit();
                    if ($attendance->connection_state === 'disconnected') {
                        $attendance->beginVisit();
                    } elseif ($attendance->connection_state === 'active') {
                        $attendance->observeConnection();
                    } else {
                        $attendance->update([
                            'connection_state' => 'active',
                            'last_seen_at' => now(),
                            'left_at' => now(),
                        ]);
                    }

                    return $attendance;
                });
            } else {
                $attendance->update(['left_at' => $now]);
            }
        }

        $durationMinutes = StudentNotificationService::enabled() && $attendance->connection_state !== null
            ? (int) floor($attendance->connected_seconds / 60)
            : ($attendance->joined_at ? max(1, (int) $attendance->joined_at->diffInMinutes($now)) : 1);

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat acknowledged.',
            'active_duration_minutes' => $durationMinutes,
            'last_seen' => $now->toISOString(),
            'status' => $attendance->status,
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

        $student = $request->user() ?: auth('student')->user();
        if (! $student) {
            return response()->json(['message' => 'Unauthorized student.'], 401);
        }

        $sessionId = (int) $validated['class_session_id'];

        $attendance = ClassAttendance::where('class_session_id', $sessionId)
            ->where('student_id', $student->id)
            ->first();

        $now = now();

        if ($attendance) {
            if (StudentNotificationService::enabled()) {
                $attendance = DB::transaction(function () use ($attendance) {
                    $attendance = ClassAttendance::whereKey($attendance->id)->lockForUpdate()->firstOrFail();
                    $attendance->closeStaleVisit();
                    if (in_array($attendance->connection_state, ['active', 'ended'], true)) {
                        $attendance->observeConnection();
                        $attendance->update(['connection_state' => 'left']);
                        $attendance->activity('class_left');
                    } elseif ($attendance->connection_state === null) {
                        $attendance->update(['left_at' => now()]);
                    }

                    return $attendance;
                });
            } else {
                $attendance->update(['left_at' => $now]);
            }

            $durationMinutes = StudentNotificationService::enabled() && $attendance->connection_state !== null
                ? (int) floor($attendance->connected_seconds / 60)
                : ($attendance->joined_at ? max(1, (int) $attendance->joined_at->diffInMinutes($now)) : 1);

            return response()->json([
                'success' => true,
                'message' => 'Class session attendance finalized.',
                'total_minutes' => $durationMinutes,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Class session attendance finalized.',
            'total_minutes' => 0,
        ], 200);
    }
}