<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $notifications = $user->notifications()->paginate(50);

        return response()->json($notifications);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $notification = $user->notifications()->find($id);
        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read']);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read']);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $notification = $user->notifications()->find($id);
        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }

    /**
     * Get unread notifications count.
     */
    public function unreadCount(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $count = $user->unreadNotifications->count();

        return response()->json(['unread_count' => $count]);
    }

    /**
     * Get system-wide notification audit logs for Admin & Staff.
     * Returns paginated records with resolved recipient groups (Student, Teacher/Tutor, Advisor, Guardian, Admin).
     * 
     * [HTTP]: GET /api/staffs/audit-logs
     */
    public function adminAuditLogs(Request $request)
    {
        $query = DatabaseNotification::query()->with('notifiable');

        // Filter by Audience / Group
        if ($request->filled('audience') && $request->audience !== 'all') {
            $audience = strtolower(trim($request->audience));
            if ($audience === 'student' || $audience === 'students') {
                $query->where('notifiable_type', \App\Models\Student::class);
            } elseif ($audience === 'guardian' || $audience === 'guardians') {
                $query->where('notifiable_type', \App\Models\Guardian::class);
            } elseif (in_array($audience, ['teacher', 'teachers', 'tutor', 'tutors'])) {
                $query->where('notifiable_type', \App\Models\Staff::class)
                      ->whereHasMorph('notifiable', [\App\Models\Staff::class], function ($q) {
                          $q->where('role', 'tutor');
                      });
            } elseif (in_array($audience, ['advisor', 'advisors', 'course_advisor', 'course_advisors'])) {
                $query->where('notifiable_type', \App\Models\Staff::class)
                      ->whereHasMorph('notifiable', [\App\Models\Staff::class], function ($q) {
                          $q->where('role', 'course_advisor');
                      });
            } elseif ($audience === 'admin' || $audience === 'admins') {
                $query->where('notifiable_type', \App\Models\Staff::class)
                      ->whereHasMorph('notifiable', [\App\Models\Staff::class], function ($q) {
                          $q->whereIn('role', ['admin', 'super_admin', 'coo']);
                      });
            }
        }

        // Filter by Read / Unread Status
        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'read') {
                $query->whereNotNull('read_at');
            } elseif ($request->status === 'unread') {
                $query->whereNull('read_at');
            }
        }

        // Search Filter (recipient name, email, notification data or type)
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('data', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%")
                  ->orWhereHasMorph('notifiable', [\App\Models\Student::class, \App\Models\Staff::class, \App\Models\Guardian::class], function ($subQ) use ($search) {
                      $subQ->where('firstname', 'like', "%{$search}%")
                           ->orWhere('surname', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginated = $query->latest('created_at')->paginate($perPage);

        // Transform notifications with human-readable recipient group metadata
        $paginated->getCollection()->transform(function ($item) {
            $notifiable = $item->notifiable;
            $group = 'system';
            $recipientRole = 'User';
            $recipientName = 'Unknown Recipient';
            $recipientEmail = '';
            $recipientAvatar = null;

            if ($notifiable instanceof \App\Models\Student) {
                $group = 'student';
                $recipientRole = 'Student';
                $recipientName = trim(($notifiable->firstname ?? '') . ' ' . ($notifiable->surname ?? ''));
                $recipientEmail = $notifiable->email ?? '';
                $recipientAvatar = $notifiable->profile_picture ?? null;
            } elseif ($notifiable instanceof \App\Models\Guardian) {
                $group = 'guardian';
                $recipientRole = 'Guardian';
                $recipientName = trim(($notifiable->firstname ?? '') . ' ' . ($notifiable->surname ?? ''));
                $recipientEmail = $notifiable->email ?? '';
            } elseif ($notifiable instanceof \App\Models\Staff) {
                $staffRole = strtolower($notifiable->role ?? 'staff');
                if ($staffRole === 'tutor') {
                    $group = 'teacher';
                    $recipientRole = 'Teacher / Tutor';
                } elseif ($staffRole === 'course_advisor') {
                    $group = 'advisor';
                    $recipientRole = 'Course Advisor';
                } elseif (in_array($staffRole, ['admin', 'super_admin'])) {
                    $group = 'admin';
                    $recipientRole = 'Administrator';
                } elseif ($staffRole === 'coo') {
                    $group = 'coo';
                    $recipientRole = 'Chief Operating Officer';
                } else {
                    $group = 'staff';
                    $recipientRole = ucfirst($staffRole);
                }
                $recipientName = trim(($notifiable->firstname ?? '') . ' ' . ($notifiable->surname ?? ''));
                $recipientEmail = $notifiable->email ?? '';
                $recipientAvatar = $notifiable->profile_picture ?? null;
            }

            // Parse notification data payload
            $parsedData = is_array($item->data) ? $item->data : json_decode($item->data, true) ?? [];
            $title = $parsedData['title'] ?? $parsedData['subject'] ?? $parsedData['type'] ?? class_basename($item->type);
            $message = $parsedData['message'] ?? $parsedData['body'] ?? (is_array($parsedData) && isset($parsedData[0]) ? $parsedData[0] : (is_string($parsedData) ? $parsedData : 'Notification dispatched'));

            return [
                'id' => $item->id,
                'type' => $item->type,
                'type_name' => class_basename($item->type),
                'title' => $title,
                'message' => $message,
                'data' => $parsedData,
                'recipient_group' => $group,
                'recipient_role' => $recipientRole,
                'recipient_name' => $recipientName ?: 'Unknown User',
                'recipient_email' => $recipientEmail,
                'recipient_avatar' => $recipientAvatar,
                'notifiable_type' => class_basename($item->notifiable_type),
                'notifiable_id' => $item->notifiable_id,
                'read_at' => $item->read_at,
                'is_read' => $item->read_at !== null,
                'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
                'created_at_human' => $item->created_at ? $item->created_at->diffForHumans() : '',
            ];
        });

        // Compute system-wide aggregate stats
        $totalCount = DatabaseNotification::count();
        $studentCount = DatabaseNotification::where('notifiable_type', \App\Models\Student::class)->count();
        $guardianCount = DatabaseNotification::where('notifiable_type', \App\Models\Guardian::class)->count();
        $teacherCount = DatabaseNotification::where('notifiable_type', \App\Models\Staff::class)
            ->whereHasMorph('notifiable', [\App\Models\Staff::class], function ($q) {
                $q->where('role', 'tutor');
            })->count();
        $advisorCount = DatabaseNotification::where('notifiable_type', \App\Models\Staff::class)
            ->whereHasMorph('notifiable', [\App\Models\Staff::class], function ($q) {
                $q->where('role', 'course_advisor');
            })->count();
        $adminCount = DatabaseNotification::where('notifiable_type', \App\Models\Staff::class)
            ->whereHasMorph('notifiable', [\App\Models\Staff::class], function ($q) {
                $q->whereIn('role', ['admin', 'super_admin', 'coo']);
            })->count();
        $unreadCount = DatabaseNotification::whereNull('read_at')->count();

        return response()->json([
            'success' => true,
            'logs' => $paginated,
            'stats' => [
                'total' => $totalCount,
                'students' => $studentCount,
                'teachers' => $teacherCount,
                'advisors' => $advisorCount,
                'guardians' => $guardianCount,
                'admins' => $adminCount,
                'unread' => $unreadCount,
            ]
        ], 200);
    }
}
