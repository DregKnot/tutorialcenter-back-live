<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Student;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\CoursesEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\AdminNotificationService;

class CourseController extends Controller
{
    /**
     * ADMIN: Create new course
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255|unique:courses,title',
            'description' => 'required|string',
            'banner' => 'required|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'required|in:active,inactive',
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $slug = Str::slug($request->title);
            if (Course::where('slug', $slug)->exists()) {
                $slug .= '-' . Str::random(6);
            }

            $bannerPath = $request->file('banner')->store('course_banners', 'public');

            $course = Course::create([
                'title' => $request->title,
                'slug' => $slug,
                'description' => $request->description,
                'banner' => $bannerPath,
                'status' => $request->status,
                'price' => $request->price,
            ]);

            DB::commit();

            AdminNotificationService::notify(
                'course_created',
                "New course created: {$course->title} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['course_id' => $course->id]
            );

            return response()->json([
                'success' => true,
                'message' => 'Course created successfully.',
                'data' => $course,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Course creation failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ADMIN: Update course
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $course = Course::find($id);
        if (!$course) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255|unique:courses,title,' . $course->id,
            'description' => 'nullable|string',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'nullable|in:active,inactive',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $data = $validator->validated();

            if (isset($data['title']) && $data['title'] !== $course->title) {
                $slug = Str::slug($data['title']);
                if (Course::where('slug', $slug)->where('id', '!=', $course->id)->exists()) {
                    $slug .= '-' . Str::random(6);
                }
                $data['slug'] = $slug;
            }

            if ($request->hasFile('banner')) {
                $data['banner'] = $request->file('banner')->store('course_banners', 'public');
            }

            $course->update($data);
            
            AdminNotificationService::notify(
                'course_updated',
                "Course updated: {$course->title} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['course_id' => $course->id]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Course updated successfully.',
                'data' => $course->fresh(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Course update failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ADMIN: Soft delete course
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $course = Course::find($id);
        if (!$course) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found.',
            ], 404);
        }

        try {
            $course->delete();

            AdminNotificationService::notify(
                'course_deleted',
                "Course deleted: {$course->title} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['course_id' => $course->id]
            );

            return response()->json([
                'success' => true,
                'message' => 'Course deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete course.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ADMIN: Restore soft-deleted course
     */
    public function restore(int $id, Request $request): JsonResponse
    {
        $course = Course::onlyTrashed()->find($id);
        if (!$course) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found or not deleted.',
            ], 404);
        }

        try {
            $course->restore();
            AdminNotificationService::notify(
                'course_restored',
                "Course restored: {$course->title} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['course_id' => $course->id]
            );
            return response()->json([
                'success' => true,
                'message' => 'Course restored successfully.',
                'data' => $course,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Course restoration failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Public: Fetch all active courses
     */
    public function index(): JsonResponse
    {
        try {
            $courses = Course::where('status', 'active')->get();
            return response()->json([
                'success' => true,
                'courses' => $courses,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch courses.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * STUDENT: Enroll in a course
     */
    public function courseEnroll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'course_id' => 'required|exists:courses,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $student = Student::find($request->student_id);
            if (is_null($student->email_verified_at) && is_null($student->tel_verified_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please verify your email or phone number before enrolling.',
                ], 403);
            }

            $course = Course::where('id', $request->course_id)->where('status', 'active')->first();
            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Course is not available for enrollment.',
                ], 404);
            }

            $months = match ($request->billing_cycle) {
                'monthly' => 1,
                'quarterly' => 3,
                'semi_annual' => 6,
                'annual' => 12,
            };

            $cost = $course->price * $months;
            if ($months > 1)
                $cost *= 0.95; // 5% discount for multi-month cycles

            $existingEnrollment = CoursesEnrollment::withTrashed()
                ->where('course_id', $course->id)
                ->where('student_id', $student->id)
                ->first();

            if ($existingEnrollment) {
                $isActivelyEnrolled = !$existingEnrollment->trashed()
                    && $existingEnrollment->status === 'active'
                    && $existingEnrollment->end_date
                    && \Carbon\Carbon::parse($existingEnrollment->end_date)->isFuture();

                if ($isActivelyEnrolled) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are already actively enrolled in this course.',
                    ], 409);
                }

                if ($existingEnrollment->trashed()) {
                    $existingEnrollment->restore();
                }

                $existingEnrollment->update([
                    'start_date' => now(),
                    'end_date' => now()->addMonths($months),
                    'billing_cycle' => $request->billing_cycle,
                    'cost' => $cost,
                    'status' => 'pending',
                ]);

                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Enrollment renewed successfully.',
                    'enrollment' => $existingEnrollment,
                ], 200);
            }

            $enrollment = CoursesEnrollment::create([
                'course_id' => $course->id,
                'student_id' => $student->id,
                'start_date' => now(),
                'end_date' => now()->addMonths($months),
                'billing_cycle' => $request->billing_cycle,
                'cost' => $cost,
                'status' => 'pending',
            ]);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Enrollment successful.',
                'enrollment' => $enrollment,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Enrollment failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Fetch active courses with enrolled subjects
     */
    public function getActiveCourses(Request $request): JsonResponse
    {
        try {
            $studentId = $request->user()->id;
            $now = now();

            $enrollments = CoursesEnrollment::with([
                'course',
                'subjects.subject'
            ])
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_date')
                      ->orWhere('end_date', '>=', $now->copy()->subMinutes(5));
                })
                ->whereHas('payments', function ($q) {
                    $q->whereIn('status', ['successful', 'paid']);
                })
                ->latest('updated_at')
                ->get();

            $courses = $enrollments->map(function ($enrollment) {
                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'course_id' => $enrollment->course_id,
                    'start_date' => $enrollment->start_date,
                    'end_date' => $enrollment->end_date,
                    'billing_cycle' => $enrollment->billing_cycle,

                    // IMPORTANT for React
                    'course' => [
                        'id' => $enrollment->course->id ?? null,
                        'title' => $enrollment->course->title ?? null,
                        'description' => $enrollment->course->description ?? null,
                    ],

                    'subjects' => $enrollment->subjects->map(function ($sub) {
                        return [
                            'id' => optional($sub->subject)->id,
                            'name' => optional($sub->subject)->name,
                            'description' => optional($sub->subject)->description,
                            'banner' => optional($sub->subject)->banner,
                            'progress' => $sub->progress ?? 0,
                        ];
                    })->values()
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Active paid courses retrieved successfully.',
                'courses' => $courses
            ]);

        } catch (\Throwable $e) {

            // VERY IMPORTANT for debugging
            // \Log::error($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active courses.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }


    

    /**
     * ADMIN: Get all courses (including inactive and soft-deleted)
     */
    public function getDisenrolledCourses(Request $request)
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | 1. Fetch Soft Deleted (Disenrolled) Enrollments
            |--------------------------------------------------------------------------
            */

            $enrollments = CoursesEnrollment::withTrashed()
                ->with([
                    'student',
                    'course',
                    'payments' => function ($q) {
                        $q->withTrashed()->orderByDesc('created_at');
                    },
                    'subjectsEnrollments' => function ($q) {
                        $q->withTrashed()->with('subject');
                    }
                ])
                ->whereNotNull('deleted_at') // Only disenrolled
                ->orderByDesc('deleted_at')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | 2. Transform Data (Clean API Response)
            |--------------------------------------------------------------------------
            */

            $data = $enrollments->map(function ($enrollment) {

                return [
                    'enrollment_id' => $enrollment->id,
                    'student' => [
                        'id' => $enrollment->student?->id,
                        'name' => trim(
                            ($enrollment->student?->firstname ?? '') . ' ' .
                            ($enrollment->student?->surname ?? '')
                        ),
                        'email' => $enrollment->student?->email,
                    ],

                    'course' => [
                        'id' => $enrollment->course?->id,
                        'title' => $enrollment->course?->title,
                    ],

                    'billing_cycle' => $enrollment->billing_cycle,
                    'start_date' => $enrollment->start_date,
                    'end_date' => $enrollment->end_date,

                    'disenrolled_at' => $enrollment->deleted_at,

                    /*
                    |--------------------------------------------------------------------------
                    | Subjects (Even if Soft Deleted)
                    |--------------------------------------------------------------------------
                    */
                    'subjects' => $enrollment->subjectsEnrollments->map(function ($se) {
                        return [
                            'subject_id' => $se->subject?->id,
                            'name' => $se->subject?->name,
                            'deleted_at' => $se->deleted_at
                        ];
                    }),

                    /*
                    |--------------------------------------------------------------------------
                    | Payments History
                    |--------------------------------------------------------------------------
                    */
                    'payments' => $enrollment->payments->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'amount' => $payment->amount,
                            'status' => $payment->status,
                            'method' => $payment->payment_method,
                            'gateway' => $payment->gateway,
                            'reference' => $payment->gateway_reference,
                            'paid_at' => $payment->paid_at,
                            'created_at' => $payment->created_at,
                        ];
                    }),

                    /*
                    |--------------------------------------------------------------------------
                    | Summary (Useful for Admin UI)
                    |--------------------------------------------------------------------------
                    */
                    'summary' => [
                        'total_paid' => $enrollment->payments
                            ->where('status', 'successful')
                            ->sum('amount'),

                        'total_refunded' => $enrollment->payments
                            ->where('status', 'refunded')
                            ->sum('amount'),

                        'cancelled_payments' => $enrollment->payments
                            ->where('status', 'cancelled')
                            ->count(),
                    ]
                ];
            });

            return response()->json([
                'message' => 'Disenrolled courses retrieved successfully',
                'count' => $data->count(),
                'data' => $data
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'message' => 'Failed to retrieve disenrolled courses',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
