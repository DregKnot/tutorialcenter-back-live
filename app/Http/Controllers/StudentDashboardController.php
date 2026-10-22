<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use App\Models\Course;
use App\Models\CoursesEnrollment;
use App\Models\Payment;
use App\Models\ClassSession;
use App\Models\ExamAttempt;
use App\Services\Admin\ExamAnalyticsService;

class StudentDashboardController extends Controller
{
    /**
     * High-Performance Single-Flight BFF Endpoint for Student Dashboard.
     * Consolidates courses, pending enrollments, streak, leaderboard rank,
     * unread notifications, recent exam attempts, and today's schedule.
     */
    public function summary(Request $request): JsonResponse
    {
        $student = $request->user('student') ?? $request->user();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated student.',
            ], 401);
        }

        $studentId = $student->id;
        $cacheKey = "student_dashboard_summary_{$studentId}";

        // Fast in-memory cache retrieval (60 second TTL)
        $data = Cache::remember($cacheKey, 60, function () use ($student, $studentId) {
            $now = now();
            $today = $now->toDateString();

            // 1. Active Courses with pruned columns (strictly unexpired)
            $enrollments = CoursesEnrollment::with([
                'course:id,title,description,banner',
                'subjects.subject' => function ($q) {
                    $q->select(['id', 'name', 'description', 'banner'])->withCount('modules');
                },
                'payments' => function ($q) {
                    $q->select(['id', 'course_enrollment_id', 'student_id', 'status', 'paid_at', 'billing_cycle', 'created_at'])
                      ->whereIn('status', ['successful', 'paid']);
                }
            ])
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->latest('updated_at')
                ->get()
                ->filter(function ($enrollment) use ($now) {
                    if ($enrollment->end_date && \Carbon\Carbon::parse($enrollment->end_date)->gte($now)) {
                        return true;
                    }
                    foreach ($enrollment->payments as $p) {
                        $paidAt = $p->paid_at ?: $p->created_at;
                        $cycle = strtolower($p->billing_cycle ?: $enrollment->billing_cycle ?: 'monthly');
                        $days = match($cycle) {
                            'weekly' => 7,
                            'quarterly' => 90,
                            'semi_annual' => 180,
                            'annual' => 365,
                            default => 30,
                        };
                        if ($paidAt && \Carbon\Carbon::parse($paidAt)->addDays($days)->gte($now)) {
                            return true;
                        }
                    }
                    return false;
                });

            $activeCourses = $enrollments->map(function ($enrollment) {
                return [
                    'id' => $enrollment->id,
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'course_id' => $enrollment->course_id,
                    'start_date' => $enrollment->start_date,
                    'end_date' => $enrollment->end_date,
                    'billing_cycle' => $enrollment->billing_cycle,
                    'status' => 'active',
                    'course' => [
                        'id' => $enrollment->course->id ?? null,
                        'title' => $enrollment->course->title ?? null,
                        'description' => $enrollment->course->description ?? null,
                        'banner' => $enrollment->course->banner ?? null,
                    ],
                    'subjects' => $enrollment->subjects->map(function ($sub) {
                        return [
                            'id' => optional($sub->subject)->id,
                            'name' => optional($sub->subject)->name,
                            'description' => optional($sub->subject)->description,
                            'banner' => optional($sub->subject)->banner,
                            'modules_count' => optional($sub->subject)->modules_count ?? (optional($sub->subject)->id ? \App\Models\Module::where('subject_id', $sub->subject->id)->count() : 0),
                            'total_modules' => optional($sub->subject)->modules_count ?? (optional($sub->subject)->id ? \App\Models\Module::where('subject_id', $sub->subject->id)->count() : 0),
                            'progress' => $sub->progress ?? 0,
                        ];
                    })->values()
                ];
            });

            // 2. Pending Enrollment Check
            $pendingEnrollment = CoursesEnrollment::with('course:id,title')
                ->where('student_id', $studentId)
                ->where('status', 'pending')
                ->latest()
                ->first();

            // 3. Pending Payment Flag (must be an active gateway payment or a claimed bank transfer)
            $hasPendingPayment = Payment::where('student_id', $studentId)
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->where('payment_method', '!=', 'bank_transfer')
                      ->orWhereNotNull('meta->bank_transfer->claimed_paid_at');
                })
                ->exists();

            // 4. Unread Notifications Count
            $unreadCount = $student->notifications()->whereNull('read_at')->count();

            // 5. Learning Streak
            $streakProgress = $student->achievementProgress()
                ->where('progress_key', 'learning_streak')
                ->whereNull('subject_id')
                ->first();
            $streak = (int) ($streakProgress?->integer_value ?? 0);

            // 6. Leaderboard Ranking
            $leaderboardRank = null;
            try {
                $analytics = app(ExamAnalyticsService::class);
                $rankings = $analytics->leaderboard(100);
                if ($rankings instanceof \Illuminate\Support\Collection || is_array($rankings)) {
                    $rankIndex = 1;
                    foreach ($rankings as $rankedStudent) {
                        if ((int) $rankedStudent->id === (int) $studentId) {
                            $leaderboardRank = $rankIndex;
                            break;
                        }
                        $rankIndex++;
                    }
                }
            } catch (\Throwable $e) {
                // Non-critical; fallback to null
            }

            // 7. Recent Exam Attempts (latest 10 completed)
            $recentAttempts = $student->examAttempts()
                ->with(['examYear.subject:id,name', 'examYear.examBody:id,name'])
                ->where('status', ExamAttempt::COMPLETED)
                ->latest('submitted_at')
                ->limit(10)
                ->get();

            // 8. Enrolled Subject IDs for today's class schedule
            $activeCourseIds = $enrollments->pluck('course_id')->filter()->unique();
            $subjectIds = $student->subjectEnrollments()
                ->whereNull('deleted_at')
                ->pluck('subject_id')
                ->unique()
                ->values();

            if ($subjectIds->isEmpty() && $activeCourseIds->isNotEmpty()) {
                $subjectIds = \App\Models\Subject::whereHas('courses', function ($q) use ($activeCourseIds) {
                    $q->whereIn('courses.id', $activeCourseIds);
                })->pluck('id');
            }

            // Sessions for today and upcoming
            $sessions = collect();
            if ($subjectIds->isNotEmpty() || $activeCourseIds->isNotEmpty()) {
                $sessions = ClassSession::with([
                    'class.subject:id,name',
                    'class.staffs:id,firstname,surname',
                    'attendances' => function ($q) use ($studentId) {
                        $q->where('student_id', $studentId);
                    }
                ])
                ->where('session_date', '>=', $today)
                ->whereIn('status', ['scheduled', 'proposed', 'rescheduled'])
                ->whereHas('class', function ($q) use ($subjectIds, $activeCourseIds) {
                    $q->whereIn('status', ['active', 'proposed', 'rescheduled']);
                    $q->where(function ($subQ) use ($subjectIds, $activeCourseIds) {
                        if ($subjectIds->isNotEmpty()) {
                            $subQ->whereIn('subject_id', $subjectIds);
                        }
                        if ($activeCourseIds->isNotEmpty()) {
                            $subQ->orWhereHas('subject.courses', fn ($cQ) => $cQ->whereIn('courses.id', $activeCourseIds));
                        }
                    });
                })
                ->orderBy('session_date')
                ->orderBy('starts_at')
                ->limit(5)
                ->get();
            }

            return [
                'courses' => $activeCourses,
                'pending_enrollment' => $pendingEnrollment,
                'has_pending_payment' => $hasPendingPayment || (bool) $pendingEnrollment,
                'unread_count' => $unreadCount,
                'streak' => $streak,
                'leaderboard_rank' => $leaderboardRank,
                'attempts' => $recentAttempts,
                'sessions' => $sessions,
                'cached_at' => $now->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
