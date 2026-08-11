<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\SubjectsEnrollment;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SubjectController extends Controller
{
    /**
     * Admin: View all subjects (including inactive)
     */
    public function allSubjects()
    {
        $subjects = Subject::with('courses')->latest()->get();

        return response()->json([
            'subjects' => $subjects,
        ]);
    }

    /**
     * Create new subject (Admin)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'banner' => 'required|image|mimes:jpg,jpeg,png|max:2048',

            'departments' => 'required|array|min:1',
            'departments.*' => 'string|max:100',

            'courses' => 'nullable|array',
            'courses.*' => 'exists:courses,id',

            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // /*
            // |--------------------------------------------------------------------------
            // | 1. Prevent Duplicate Subject (same name + overlapping departments)
            // |--------------------------------------------------------------------------
            // */

            // $existing = Subject::where('name', $request->name)
            //     ->get()
            //     ->first(function ($subject) use ($request) {
            //         return !empty(array_intersect(
            //             $subject->departments ?? [],
            //             $request->departments
            //         ));
            //     });

            // if ($existing) {
            //     DB::rollBack();

            //     return response()->json([
            //         'message' => 'Subject already exists for selected departments',
            //     ], 409);
            // }

            /*
            |--------------------------------------------------------------------------
            | 1. Prevent Duplicate Subject
            |--------------------------------------------------------------------------
            | A subject can exist in different courses.
            |
            | Example:
            | Mathematics + WAEC     → allowed
            | Mathematics + GCE      → allowed
            | Mathematics + NECO     → allowed
            |
            | But:
            | Mathematics + WAEC     → cannot be created twice
            |--------------------------------------------------------------------------
            */

            if ($request->filled('courses')) {

                $existing = Subject::where('name', $request->name)
                    ->whereHas('courses', function ($query) use ($request) {
                        $query->whereIn('courses.id', $request->courses);
                    })
                    ->first();

                if ($existing) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'This subject already exists in one or more of the selected courses.',
                    ], 409);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Upload Banner
            |--------------------------------------------------------------------------
            */

            $bannerPath = $request->file('banner')->store('subject_banners', 'public');

            /*
        |--------------------------------------------------------------------------
        | 3. Create Subject
        |--------------------------------------------------------------------------
        */

            $subject = Subject::create([
                'name' => $request->name,
                'description' => $request->description,
                'banner' => $bannerPath,
                'departments' => array_values($request->departments),
                'status' => $request->status,
            ]);

            /*
        |--------------------------------------------------------------------------
        | 4. Attach Courses Using Pivot Table
        |--------------------------------------------------------------------------
        */

            if ($request->filled('courses')) {
                $subject->courses()->sync($request->courses);
            }

            DB::commit();
            AdminNotificationService::notify("New Subject Created: {$subject->name}", "A new subject has been created with ID: {$subject->id}. for departments: " . implode(', ', $subject->departments) . "under courses: " . implode(', ', $subject->courses()->pluck('title')->toArray()));
            return response()->json([
                'message' => 'Subject created successfully.',
                'subject' => $subject->load('courses'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create subject.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update subject (Admin)
     */
    public function update(Request $request, $id)
    {
        $subject = Subject::find($id);

        if (!$subject) {
            return response()->json([
                'message' => 'Subject not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',

            'departments' => 'nullable|array|min:1',
            'departments.*' => 'string|max:100',

            'courses' => 'nullable|array',
            'courses.*' => 'exists:courses,id',

            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
        |--------------------------------------------------------------------------
        | 1. Prevent Duplicate Subject (same name + overlapping departments)
        |--------------------------------------------------------------------------
        */

            $newName = $request->filled('name')
                ? $request->name
                : $subject->name;

            $newDepartments = $request->filled('departments')
                ? $request->departments
                : ($subject->departments ?? []);

            $existing = Subject::where('id', '!=', $subject->id)
                ->where('name', $newName)
                ->get()
                ->first(function ($item) use ($newDepartments) {
                    return !empty(array_intersect(
                        $item->departments ?? [],
                        $newDepartments
                    ));
                });

            if ($existing) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Subject already exists for selected departments',
                ], 409);
            }

            /*
        |--------------------------------------------------------------------------
        | 2. Prepare Update Data
        |--------------------------------------------------------------------------
        */

            $data = [];

            if ($request->filled('name')) {
                $data['name'] = $request->name;
            }

            if ($request->filled('description')) {
                $data['description'] = $request->description;
            }

            if ($request->filled('departments')) {
                $data['departments'] = array_values($request->departments);
            }

            if ($request->filled('status')) {
                $data['status'] = $request->status;
            }

            /*
        |--------------------------------------------------------------------------
        | 3. Upload New Banner
        |--------------------------------------------------------------------------
        */

            if ($request->hasFile('banner')) {
                $data['banner'] = $request->file('banner')
                    ->store('subject_banners', 'public');
            }

            /*
        |--------------------------------------------------------------------------
        | 4. Update Subject
        |--------------------------------------------------------------------------
        */

            $subject->update($data);

            /*
        |--------------------------------------------------------------------------
        | 5. Sync Courses
        |--------------------------------------------------------------------------
        */

            if ($request->has('courses')) {
                $subject->courses()->sync($request->courses ?? []);
            }

            DB::commit();

            return response()->json([
                'message' => 'Subject updated successfully.',
                'subject' => $subject->fresh()->load('courses'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update subject.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Soft delete subject (Admin)
     */
    public function destroy($id)
    {
        $subject = Subject::find($id);

        if (!$subject) {
            return response()->json([
                'message' => 'Subject not found.',
            ], 404);
        }

        $subject->delete();

        return response()->json([
            'message' => 'Subject deleted successfully.',
        ]);
    }

    /**
     * Restore soft-deleted subject (Admin)
     */
    public function restore($id)
    {
        $subject = Subject::onlyTrashed()->find($id);

        if (!$subject) {
            return response()->json([
                'message' => 'Subject not found or not deleted.',
            ], 404);
        }

        $subject->restore();

        return response()->json([
            'message' => 'Subject restored successfully.',
            'subject' => $subject,
        ]);
    }

    /*
     * Public Method: List all active subjects
     */
    public function index()
    {
        $subjects = Subject::where('status', 'active')->get();

        return response()->json([
            'subjects' => $subjects,
        ]);
    }

    /*
     * Public Method: List subjects by course
     */
    public function subjectsByCourse(int $courseId)
    {
        try {
            $subjects = Subject::where('status', 'active')
                ->whereHas('courses', function ($query) use ($courseId) {
                    $query->where('courses.id', $courseId);
                })
                ->with('courses')
                ->get();

            return response()->json([
                'message' => 'Subjects fetched successfully.',
                'subjects' => $subjects,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve subjects.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * Public Method: List subjects by course and department
     */
    public function subjectsByCourseAndDepartment(int $courseId, string $department)
    {
        try {
            $subjects = Subject::query()
                ->where('status', 'active')
                ->whereHas('courses', function ($query) use ($courseId) {
                    $query->where('courses.id', $courseId);
                })
                ->whereJsonContains('departments', $department)
                ->with('courses')
                ->get();

            return response()->json([
                'message' => 'Subjects fetched successfully.',
                'subjects' => $subjects,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve subjects.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * Public Method: Subject enrollment
     */
    public function subjectEnroll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_enrollment_id' => 'required|exists:courses_enrollments,id',
            'subject_id' => 'required|exists:subjects,id',
            'student_id' => 'required|exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Verify subject enrollment exists already for that course and that student
            $existingEnrollment = SubjectsEnrollment::where('course_enrollment_id', $request->course_enrollment_id)
                ->where('subject_id', $request->subject_id)
                ->where('student_id', $request->student_id)
                ->first();
            if ($existingEnrollment) {
                return response()->json([
                    'message' => 'Subject already enrolled for this course and student.',
                ], 409);
            }

            // Create subject enrollment
            SubjectsEnrollment::create([
                'course_enrollment_id' => $request->course_enrollment_id,
                'subject_id' => $request->subject_id,
                'student_id' => $request->student_id,
            ]);

            return response()->json([
                'message' => 'Subject enrolled successfully.',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to enroll subject.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
