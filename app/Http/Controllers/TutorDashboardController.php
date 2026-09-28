<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Classes;
use App\Models\ClassSession;
use App\Models\Feedback;
use App\Models\Staff;
use App\Services\AssessmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TutorDashboardController extends Controller
{
    /**
     * Mark a class session as concluded by the tutor.
     */
    public function concludeSession(Request $request, $classSession): JsonResponse
    {
        try {
            $session = $classSession instanceof ClassSession ? $classSession : ClassSession::find($classSession);
            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Class session not found.'
                ], 404);
            }

            $session->status = 'completed';
            $session->save();

            return response()->json([
                'success' => true,
                'message' => 'Session marked completed successfully.',
                'data' => $session,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to conclude session',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get aggregated overview metrics, syllabus delivery progress, and action hub for Tutor Dashboard.
     */
    public function overview(Request $request): JsonResponse
    {
        try {
            $staff = $request->user() ?: auth('staff')->user();

            if (!$staff) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized tutor'
                ], 401);
            }

            // 1. Tutor active classes
            $classes = Classes::with([
                    'subject.courses',
                    'staffs',
                    'schedules.sessions' => fn($q) => $q->withCount(['views as views']),
                    'schedules.sessions.attendances',
                ])
                ->whereHas('subject', fn($q) => $q->where('status', 'active'))
                ->where('status', 'active')
                ->whereHas('staffs', fn($q) => $q->where('staffs.id', $staff->id))
                ->get();

            if ($classes->isEmpty()) {
                // If tutor not linked to specific class via pivot, fallback to classes for their subject/qualification if available
                $classes = Classes::with([
                        'subject.courses',
                        'staffs',
                        'schedules.sessions' => fn($q) => $q->withCount(['views as views']),
                        'schedules.sessions.attendances',
                    ])
                    ->whereHas('subject', fn($q) => $q->where('status', 'active'))
                    ->where('status', 'active')
                    ->take(5)
                    ->get();
            }

            $classIds = $classes->pluck('id')->toArray();

            // 2. Syllabus & Curriculum Delivery Calculations per Class
            $totalAssignedSessions = 0;
            $totalCompletedSessions = 0;

            $classProgressList = $classes->map(function ($class) use (&$totalAssignedSessions, &$totalCompletedSessions) {
                $sessions = $class->schedules->flatMap->sessions;

                $classTotalSessions = $sessions->count();
                $classCompletedSessions = $sessions->filter(function ($s) {
                    if ($s->status === 'completed') return true;
                    if ($s->status === 'cancelled') return false;

                    $dateStr = $s->session_date ? ($s->session_date instanceof Carbon ? $s->session_date->toDateString() : substr((string)$s->session_date, 0, 10)) : null;
                    if (!$dateStr) return false;

                    $timeStr = $s->ends_at ?: ($s->starts_at ?: '23:59:59');
                    if (strlen($timeStr) === 5) $timeStr .= ':00';
                    try {
                        $sessionEnd = Carbon::parse("{$dateStr} {$timeStr}");
                        return $sessionEnd->isPast();
                    } catch (\Throwable) {
                        return false;
                    }
                })->count();

                $totalAssignedSessions += $classTotalSessions;
                $totalCompletedSessions += $classCompletedSessions;

                $classProgress = $classTotalSessions > 0
                    ? round(($classCompletedSessions / $classTotalSessions) * 100)
                    : 0;

                // Next upcoming session for this specific class
                $nextSession = $sessions->filter(function ($s) {
                    if ($s->status === 'cancelled' || $s->status === 'completed') return false;
                    $dateStr = $s->session_date ? ($s->session_date instanceof Carbon ? $s->session_date->toDateString() : substr((string)$s->session_date, 0, 10)) : null;
                    if (!$dateStr) return false;

                    $timeStr = $s->ends_at ?: ($s->starts_at ?: '23:59:59');
                    if (strlen($timeStr) === 5) $timeStr .= ':00';
                    try {
                        $sessionEnd = Carbon::parse("{$dateStr} {$timeStr}");
                        return $sessionEnd->isFuture();
                    } catch (\Throwable) {
                        return false;
                    }
                })->sortBy(function ($s) {
                    $dateStr = $s->session_date ? ($s->session_date instanceof Carbon ? $s->session_date->toDateString() : substr((string)$s->session_date, 0, 10)) : '9999-12-31';
                    return $dateStr . ' ' . ($s->starts_at ?: '00:00:00');
                })->first();

                return [
                    'id' => $class->id,
                    'title' => $class->title,
                    'subject_name' => $class->subject?->name ?? 'General',
                    'subject_code' => $class->subject?->code ?? '',
                    'course_name' => $class->subject?->courses?->first()?->title ?? '',
                    'total_sessions' => $classTotalSessions,
                    'completed_sessions' => $classCompletedSessions,
                    'remaining_sessions' => max(0, $classTotalSessions - $classCompletedSessions),
                    'progress_percent' => $classProgress,
                    'next_session' => $nextSession ? [
                        'id' => $nextSession->id,
                        'title' => $nextSession->title,
                        'session_date' => $nextSession->session_date ? $nextSession->session_date->toDateString() : null,
                        'starts_at' => $nextSession->starts_at,
                        'ends_at' => $nextSession->ends_at,
                        'class_link' => $nextSession->class_link,
                    ] : null,
                ];
            });

            // Overall delivery percentage
            $overallProgress = $totalAssignedSessions > 0
                ? round(($totalCompletedSessions / $totalAssignedSessions) * 100)
                : 0;

            // 3. Monthly Sessions Delivered / Scheduled
            $sessionsThisMonth = ClassSession::whereIn('class_id', $classIds)
                ->whereBetween('session_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count();

            // 4. Assessments & Pending Submissions to Grade
            $assessmentService = new AssessmentService();
            $tutorAssessments = $assessmentService->tutorAssessments($staff);

            $pendingGradingCount = 0;
            $totalSubmissionsCount = 0;
            $gradedSubmissionsCount = 0;

            foreach ($tutorAssessments as $a) {
                $sub = (int) ($a['stats']['submitted_count'] ?? 0);
                $grd = (int) ($a['stats']['graded_count'] ?? 0);
                $pendingGradingCount += max(0, $sub - $grd);
                $totalSubmissionsCount += $sub;
                $gradedSubmissionsCount += $grd;
            }

            // 5. Today's and Next Imminent Class Sessions
            $todayDate = now()->toDateString();
            $nowTime = now()->toTimeString();

            $sessionQuery = ClassSession::with([
                'class.subject.courses',
                'class.staffs',
                'views',
                'attendances',
            ])->whereIn('class_id', $classIds);

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

            if (!$nextClass) {
                $nextClass = (clone $sessionQuery)
                    ->whereDate('session_date', '>=', $todayDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->orderBy('session_date', 'asc')
                    ->orderBy('starts_at', 'asc')
                    ->first();
            }

            $todayClasses = (clone $sessionQuery)
                ->whereDate('session_date', $todayDate)
                ->whereNotIn('status', ['cancelled'])
                ->orderBy('starts_at', 'asc')
                ->get();

            // Client-passed unreported session IDs (e.g. from localStorage)
            $rawUnreportedIds = $request->input('unreported_ids', []);
            if (is_string($rawUnreportedIds)) {
                $rawUnreportedIds = array_filter(explode(',', $rawUnreportedIds));
            }
            $unreportedIds = array_map('intval', (array)$rawUnreportedIds);

            // 6. Pending Post-Class Reports
            // STRICT BUSINESS RULES:
            // a) NEVER show future classes (session_date > todayDate). A class in front (e.g. tomorrow Sep 28) can NEVER have a post-class report.
            // b) For today: ONLY earlier times (starts_at <= nowTime or marked completed). Future hours today are excluded.
            // c) For earlier days: only recent earlier times (within the past 3-4 days), NOT for all time.
            // d) Capped to a clean maximum of 4 items so it doesn't flood the dashboard.
            $recentPastSessions = (clone $sessionQuery)
                ->whereDate('session_date', '<=', $todayDate)
                ->where(function ($q) use ($todayDate, $nowTime, $unreportedIds) {
                    // Earlier times today
                    $q->where(function ($todayQ) use ($todayDate, $nowTime) {
                        $todayQ->whereDate('session_date', $todayDate)
                               ->where(function ($tq) use ($nowTime) {
                                   $tq->where('starts_at', '<=', $nowTime)
                                      ->orWhere('status', 'completed');
                               });
                    })
                    // Or recent past days (last 4 days)
                    ->orWhere(function ($pastQ) use ($todayDate) {
                        $pastQ->whereDate('session_date', '<', $todayDate)
                              ->whereDate('session_date', '>=', now()->subDays(4)->toDateString());
                    });

                    // Client unreported sessions that took place today or earlier
                    if (!empty($unreportedIds)) {
                        $q->orWhere(function ($locQ) use ($unreportedIds, $todayDate) {
                            $locQ->whereIn('id', $unreportedIds)
                                 ->whereDate('session_date', '<=', $todayDate);
                        });
                    }
                })
                ->whereNotIn('status', ['cancelled'])
                ->orderBy('session_date', 'desc')
                ->orderBy('starts_at', 'desc')
                ->get();

            $pendingReports = $recentPastSessions->filter(function ($s) use ($staff) {
                // Must NOT have an existing Tutor Post-Class Report (exclude Course Advisor reports)
                return !Feedback::where('feedbackable_type', Classes::class)
                    ->where('feedbackable_id', $s->class_id)
                    ->where('title', 'not like', '%Course Advisor%')
                    ->where(function ($fq) use ($s) {
                        $fq->where('ratings', 'like', '%"session_id":' . $s->id . '%')
                           ->orWhere('ratings', 'like', '%"session_id": "' . $s->id . '"%')
                           ->orWhere('comment', 'like', '%"session_id":' . $s->id . '%');
                    })
                    ->exists();
            })->values()->take(4);

            return response()->json([
                'success' => true,
                'data' => [
                    'kpis' => [
                        'total_classes' => $classes->count(),
                        'completed_sessions' => $totalCompletedSessions,
                        'total_sessions' => $totalAssignedSessions,
                        'sessions_this_month' => $sessionsThisMonth,
                        'pending_grading_count' => $pendingGradingCount,
                        'total_assessments' => count($tutorAssessments),
                        'total_submissions' => $totalSubmissionsCount,
                        'overall_delivery_progress' => $overallProgress,
                    ],
                    'class_progress' => $classProgressList,
                    'next_class' => $nextClass,
                    'today_classes' => $todayClasses,
                    'pending_reports' => $pendingReports,
                    'assessments_summary' => array_slice($tutorAssessments, 0, 5),
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tutor overview',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}