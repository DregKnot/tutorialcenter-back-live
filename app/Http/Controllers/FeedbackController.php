<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\FeedbackService;

class FeedbackController extends Controller
{
    protected $feedbackService;

    public function __construct(
        FeedbackService $feedbackService
    ) {
        $this->feedbackService = $feedbackService;
    }

    /**
     * Submit Feedback
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [

            'feedbackable_type' => [
                'required',
                'string',
            ],

            'feedbackable_id' => [
                'required',
                'integer',
            ],

            'rating' => [
                'required',
                'integer',
                'between:1,5',
            ],

            'title' => [
                'nullable',
                'string',
                'max:255',
            ],

            'comment' => [
                'nullable',
                'string',
            ],

            'ratings' => [
                'nullable',
                'array',
            ],

            'would_recommend' => [
                'boolean',
            ],

            'is_anonymous' => [
                'boolean',
            ],

        ]);

        if ($validator->fails()) {

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);

        }

        try {

            $feedback = $this->feedbackService->create(
                $request->user(),
                $request->all()
            );

            return response()->json([
                'message' => 'Feedback submitted successfully.',
                'data' => $feedback,
            ], 201);

        } catch (\Exception $e) {

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);

        }
    }

    /**
     * My Feedback
     */
    public function index(Request $request)
    {
        $feedbacks = $request->user()
            ->feedbacks()
            ->latest()
            ->paginate(20);

        return response()->json($feedbacks);
    }

    /**
     * View Feedback
     */
    public function show(
        Feedback $feedback,
        Request $request
    ) {

        if (
            $feedback->feedbacker_id != $request->user()->id ||
            $feedback->feedbacker_type != get_class($request->user())
        ) {
            abort(403);
        }

        return response()->json([
            'data' => $feedback->load([
                'feedbackable',
                'feedbacker',
            ]),
        ]);
    }

    /**
     * Update Feedback
     */
    public function update(
        Request $request,
        Feedback $feedback
    ) {
        if (
            $feedback->feedbacker_id != $request->user()->id ||
            $feedback->feedbacker_type != get_class($request->user())
        ) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [

            'rating' => 'sometimes|integer|between:1,5',

            'title' => 'nullable|string|max:255',

            'comment' => 'nullable|string',

            'ratings' => 'nullable|array',

            'would_recommend' => 'boolean',

            'is_anonymous' => 'boolean',

        ]);

        if ($validator->fails()) {

            return response()->json([
                'errors' => $validator->errors(),
            ], 422);

        }

        $feedback = $this->feedbackService->update(
            $feedback,
            $request->all()
        );

        return response()->json([
            'message' => 'Feedback updated successfully.',
            'data' => $feedback,
        ]);
    }

    /**
     * Delete Feedback
     */
    public function destroy(
        Feedback $feedback,
        Request $request
    ) {
        if (
            $feedback->feedbacker_id != $request->user()->id ||
            $feedback->feedbacker_type != get_class($request->user())
        ) {
            abort(403);
        }

        $feedback->delete();

        return response()->json([
            'message' => 'Feedback deleted successfully.',
        ]);
    }

    /**
     * Admin: Get all feedbacks across all categories, subjects, courses, tutors, and authors.
     * 
     * [HTTP]: GET /api/admin/feedbacks/all or GET /api/staffs/feedbacks/all
     */
    public function adminIndex(Request $request)
    {
        $query = Feedback::query()->with(['feedbacker', 'feedbackable']);

        // Filter by Category / Target Model
        if ($request->filled('category') && $request->category !== 'all') {
            $cat = strtolower(trim($request->category));
            if ($cat === 'course' || $cat === 'courses') {
                $query->where('feedbackable_type', \App\Models\Course::class);
            } elseif ($cat === 'subject' || $cat === 'subjects') {
                $query->where('feedbackable_type', \App\Models\Subject::class);
            } elseif ($cat === 'class' || $cat === 'classes' || $cat === 'master_class') {
                $query->where('feedbackable_type', \App\Models\Classes::class);
            } elseif ($cat === 'staff' || $cat === 'tutor' || $cat === 'teacher') {
                $query->where('feedbackable_type', \App\Models\Staff::class);
            } elseif ($cat === 'exam' || $cat === 'exam_attempt' || $cat === 'exams') {
                $query->where('feedbackable_type', \App\Models\ExamAttempt::class);
            }
        }

        // Filter by Author Group
        if ($request->filled('author_group') && $request->author_group !== 'all') {
            $authorGroup = strtolower(trim($request->author_group));
            if ($authorGroup === 'student' || $authorGroup === 'students') {
                $query->where('feedbacker_type', \App\Models\Student::class);
            } elseif ($authorGroup === 'guardian' || $authorGroup === 'guardians') {
                $query->where('feedbacker_type', \App\Models\Guardian::class);
            } elseif ($authorGroup === 'staff' || $authorGroup === 'tutor' || $authorGroup === 'teacher') {
                $query->where('feedbacker_type', \App\Models\Staff::class);
            }
        }

        // Filter by Rating
        if ($request->filled('rating') && $request->rating !== 'all') {
            $query->where('rating', (int)$request->rating);
        }

        // Filter by Recommendation
        if ($request->filled('recommend') && $request->recommend !== 'all') {
            $query->where('would_recommend', $request->recommend === 'yes' || $request->recommend === '1');
        }

        // Filter by Status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search Filter
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('comment', 'like', "%{$search}%")
                  ->orWhereHasMorph('feedbacker', [\App\Models\Student::class, \App\Models\Staff::class, \App\Models\Guardian::class], function ($subQ) use ($search) {
                      $subQ->where('firstname', 'like', "%{$search}%")
                           ->orWhere('surname', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = (int)$request->input('per_page', 20);
        $paginated = $query->latest('created_at')->paginate($perPage);

        // Transform Collection with rich human-readable metadata
        $paginated->getCollection()->transform(function ($item) {
            $feedbacker = $item->feedbacker;
            $feedbackable = $item->feedbackable;

            // Resolve Reviewer Info
            $authorGroup = 'user';
            $authorRole = 'User';
            $authorName = 'Anonymous Reviewer';
            $authorEmail = '';
            $authorAvatar = null;

            if ($feedbacker instanceof \App\Models\Student) {
                $authorGroup = 'student';
                $authorRole = 'Student';
                $authorName = trim(($feedbacker->firstname ?? '') . ' ' . ($feedbacker->surname ?? ''));
                $authorEmail = $feedbacker->email ?? '';
                $authorAvatar = $feedbacker->profile_picture ?? null;
            } elseif ($feedbacker instanceof \App\Models\Guardian) {
                $authorGroup = 'guardian';
                $authorRole = 'Guardian';
                $authorName = trim(($feedbacker->firstname ?? '') . ' ' . ($feedbacker->surname ?? ''));
                $authorEmail = $feedbacker->email ?? '';
            } elseif ($feedbacker instanceof \App\Models\Staff) {
                $authorGroup = 'staff';
                $authorRole = ucfirst($feedbacker->role ?? 'Staff');
                $authorName = trim(($feedbacker->firstname ?? '') . ' ' . ($feedbacker->surname ?? ''));
                $authorEmail = $feedbacker->email ?? '';
                $authorAvatar = $feedbacker->profile_picture ?? null;
            }

            // Resolve Target Model (Category & Title)
            $category = 'General';
            $categoryKey = 'general';
            $targetTitle = 'System & Platform';

            if ($feedbackable instanceof \App\Models\Course) {
                $category = 'Course';
                $categoryKey = 'course';
                $targetTitle = $feedbackable->title ?? $feedbackable->name ?? 'Course';
            } elseif ($feedbackable instanceof \App\Models\Subject) {
                $category = 'Subject';
                $categoryKey = 'subject';
                $targetTitle = $feedbackable->name ?? 'Subject';
            } elseif ($feedbackable instanceof \App\Models\Classes) {
                $category = 'Master Class';
                $categoryKey = 'class';
                $targetTitle = $feedbackable->title ?? ($feedbackable->subject ? $feedbackable->subject->name . ' Class' : 'Master Class');
            } elseif ($feedbackable instanceof \App\Models\Staff) {
                $category = 'Tutor / Teacher';
                $categoryKey = 'staff';
                $targetTitle = trim(($feedbackable->firstname ?? '') . ' ' . ($feedbackable->surname ?? '')) . ' (' . ucfirst($feedbackable->role ?? 'Tutor') . ')';
            } elseif ($feedbackable instanceof \App\Models\ExamAttempt) {
                $category = 'Exam';
                $categoryKey = 'exam';
                $targetTitle = 'Exam Attempt #' . $feedbackable->id;
            }

            $ratingsBreakdown = is_array($item->ratings) ? $item->ratings : json_decode($item->ratings, true) ?? [];

            return [
                'id' => $item->id,
                'author_group' => $authorGroup,
                'author_role' => $authorRole,
                'author_name' => $item->is_anonymous ? 'Anonymous (' . $authorRole . ')' : ($authorName ?: 'Unknown User'),
                'author_email' => $item->is_anonymous ? '' : $authorEmail,
                'author_avatar' => $item->is_anonymous ? null : $authorAvatar,
                'is_anonymous' => (bool)$item->is_anonymous,
                'category' => $category,
                'category_key' => $categoryKey,
                'target_title' => $targetTitle,
                'feedbackable_type' => class_basename($item->feedbackable_type),
                'feedbackable_id' => $item->feedbackable_id,
                'rating' => (int)$item->rating,
                'title' => $item->title ?: 'Feedback Review',
                'comment' => $item->comment ?: '',
                'ratings' => $ratingsBreakdown,
                'would_recommend' => (bool)$item->would_recommend,
                'status' => $item->status ?? 'published',
                'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
                'created_at_human' => $item->created_at ? $item->created_at->diffForHumans() : '',
            ];
        });

        // Compute System-Wide Feedback Metrics & Analytics
        $totalCount = Feedback::count();
        $avgRating = $totalCount > 0 ? round(Feedback::avg('rating'), 1) : 5.0;
        $recommendCount = Feedback::where('would_recommend', true)->count();
        $recommendRate = $totalCount > 0 ? round(($recommendCount / $totalCount) * 100) : 100;

        $starCounts = [
            5 => Feedback::where('rating', 5)->count(),
            4 => Feedback::where('rating', 4)->count(),
            3 => Feedback::where('rating', 3)->count(),
            2 => Feedback::where('rating', 2)->count(),
            1 => Feedback::where('rating', 1)->count(),
        ];

        $categoryCounts = [
            'all' => $totalCount,
            'course' => Feedback::where('feedbackable_type', \App\Models\Course::class)->count(),
            'subject' => Feedback::where('feedbackable_type', \App\Models\Subject::class)->count(),
            'class' => Feedback::where('feedbackable_type', \App\Models\Classes::class)->count(),
            'staff' => Feedback::where('feedbackable_type', \App\Models\Staff::class)->count(),
            'exam' => Feedback::where('feedbackable_type', \App\Models\ExamAttempt::class)->count(),
        ];

        $authorCounts = [
            'all' => $totalCount,
            'student' => Feedback::where('feedbacker_type', \App\Models\Student::class)->count(),
            'guardian' => Feedback::where('feedbacker_type', \App\Models\Guardian::class)->count(),
            'staff' => Feedback::where('feedbacker_type', \App\Models\Staff::class)->count(),
        ];

        return response()->json([
            'success' => true,
            'feedbacks' => $paginated,
            'stats' => [
                'total' => $totalCount,
                'average_rating' => $avgRating,
                'recommendation_rate' => $recommendRate,
                'stars' => $starCounts,
                'categories' => $categoryCounts,
                'authors' => $authorCounts,
            ]
        ], 200);
    }

    /**
     * Admin: Toggle status of feedback (published / hidden)
     */
    public function adminToggleStatus(Request $request, $id)
    {
        $feedback = Feedback::findOrFail($id);
        $newStatus = $feedback->status === 'published' ? 'hidden' : 'published';
        if ($request->filled('status')) {
            $newStatus = $request->status === 'hidden' ? 'hidden' : 'published';
        }
        $feedback->status = $newStatus;
        $feedback->save();

        return response()->json([
            'success' => true,
            'message' => "Feedback status updated to {$newStatus}.",
            'status' => $newStatus,
        ], 200);
    }

    /**
     * Admin: Delete feedback
     */
    public function adminDestroy(Request $request, $id)
    {
        $feedback = Feedback::findOrFail($id);
        $feedback->delete();

        return response()->json([
            'success' => true,
            'message' => 'Feedback deleted successfully.',
        ], 200);
    }

    /**
     * Staff/Tutor: Submit Official Post-Class Tutor Report
     * [HTTP]: POST /api/staffs/classes/tutor-report
     */
    public function storeTutorReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_session_id' => 'required|exists:class_sessions,id',
            'attendance' => 'required|array',
            'attendance.present_count' => 'required|integer|min:0',
            'attendance.total_count' => 'required|integer|min:0',
            'attendance.has_issues' => 'required|boolean',
            'attendance.issues_detail' => 'nullable|string',

            'lesson_delivery' => 'required|array',
            'lesson_delivery.aspects_covered' => 'required|string',
            'lesson_delivery.completion_status' => 'required|string|in:Fully,Partially,Not completed',
            'lesson_delivery.left_reason' => 'nullable|string',

            'student_understanding' => 'required|array',
            'student_understanding.evidence' => 'required|string',
            'student_understanding.struggled_concepts' => 'nullable|string',
            'student_understanding.students_needing_attention' => 'nullable|string',

            'student_engagement' => 'required|array',
            'student_engagement.participation_level' => 'required|string|in:Very Active,Active,Moderate,Low',
            'student_engagement.responded_well_to' => 'nullable|string',
            'student_engagement.issues_affecting_concentration' => 'nullable|string',

            'assessment' => 'required|array',
            'assessment.assessed_today' => 'required|boolean',
            'assessment.general_performance' => 'nullable|string|in:Excellent,Good,Average,Poor',

            'class_challenges' => 'required|array',
            'class_challenges.challenges' => 'nullable|array',
            'class_challenges.other_challenge' => 'nullable|string',
            'class_challenges.explanation' => 'nullable|string',

            'next_steps' => 'required|array',
            'next_steps.improvement_plan' => 'required|string',
            'next_steps.support_required' => 'required|boolean',
            'next_steps.support_detail' => 'nullable|string',

            'overall_assessment' => 'required|array',
            'overall_assessment.management_summary' => 'required|string',
            'overall_assessment.tutor_signature' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed. Please complete all required sections of the report.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $staff = $request->user();
            $sessionId = (int) $request->input('class_session_id');
            $session = \App\Models\ClassSession::with(['class.subject'])->findOrFail($sessionId);

            // Compute equivalent numerical score for reporting/statistics (1-5 scale)
            $performanceScore = match ($request->input('assessment.general_performance')) {
                'Excellent' => 5,
                'Good' => 4,
                'Average' => 3,
                'Poor' => 2,
                default => 4,
            };

            $reportData = [
                'session_metadata' => [
                    'session_id' => $session->id,
                    'class_id' => $session->class_id,
                    'subject' => $session->class?->subject?->name ?? 'General Subject',
                    'class_title' => $session->class?->title ?? 'Masterclass',
                    'date' => $session->session_date ? $session->session_date->toDateString() : today()->toDateString(),
                    'time' => "{$session->starts_at} - {$session->ends_at}",
                    'tutor_name' => trim(($staff->firstname ?? 'Tutor') . ' ' . ($staff->surname ?? '')),
                ],
                'attendance' => $request->input('attendance'),
                'lesson_delivery' => $request->input('lesson_delivery'),
                'student_understanding' => $request->input('student_understanding'),
                'student_engagement' => $request->input('student_engagement'),
                'assessment' => $request->input('assessment'),
                'class_challenges' => $request->input('class_challenges'),
                'next_steps' => $request->input('next_steps'),
                'overall_assessment' => array_merge(
                    $request->input('overall_assessment', []),
                    [
                        'tutor_name' => trim(($staff->firstname ?? 'Tutor') . ' ' . ($staff->surname ?? '')),
                        'submitted_at' => now()->toIso8601String(),
                    ]
                ),
            ];

            // Find existing feedback or create new
            $feedback = \App\Models\Feedback::updateOrCreate(
                [
'feedbacker_type' => $staff ? get_class($staff) : \App\Models\Staff::class,
                    'feedbacker_id' => $staff ? $staff->id : ($request->input('staff_id') ?: ($session->class?->staffs?->first()?->id ?? 1)),
                    'feedbackable_type' => \App\Models\Classes::class,
                    'feedbackable_id' => $session->class_id,
                ],
                [
                    'rating' => $performanceScore,
                    'title' => "Post-Class Report: " . ($session->class?->title ?? 'Lesson'),
                    'comment' => $request->input('overall_assessment.management_summary'),
                    'ratings' => $reportData,
                    'would_recommend' => true,
                    'is_anonymous' => false,
'status' => 'published',
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Post-Class Tutor Report submitted successfully to management.',
                'data' => $feedback,
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('storeTutorReport error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit report. ' . $e->getMessage(),
            ], 500);
        }
    }
}
