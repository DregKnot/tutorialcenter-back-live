<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Module;
use App\Services\LessonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LessonController extends Controller
{
    public function __construct(private LessonService $lessonService)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Staff / Admin Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * List all lessons in a module.
     */
    public function index(Request $request, int $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $lessons = $this->lessonService->listByModule($module);

        return response()->json([
            'status'  => 'success',
            'lessons' => $lessons,
        ]);
    }

    /**
     * Create a new lesson inside a module.
     */
    public function store(Request $request, int $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validator = Validator::make($request->all(), [
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'order'            => 'nullable|integer|min:0',
            'duration_minutes' => 'nullable|integer|min:1',
            'status'           => 'nullable|string|in:draft,published,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $lesson = $this->lessonService->create($request->user(), $module, $validator->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Lesson created successfully',
            'lesson'  => $lesson,
        ], 201);
    }

    /**
     * View lesson details (materials, quizzes, questions).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $detailed = $this->lessonService->detail($lesson);

        return response()->json([
            'status' => 'success',
            'lesson' => $detailed,
        ]);
    }

    /**
     * Update lesson details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'            => 'sometimes|required|string|max:255',
            'description'      => 'nullable|string',
            'order'            => 'nullable|integer|min:0',
            'duration_minutes' => 'nullable|integer|min:1',
            'status'           => 'nullable|string|in:draft,published,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = $this->lessonService->update($request->user(), $lesson, $validator->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Lesson updated successfully',
            'lesson'  => $updated,
        ]);
    }

    /**
     * Publish a lesson.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $published = $this->lessonService->publish($request->user(), $lesson);

        return response()->json([
            'status'  => 'success',
            'message' => 'Lesson published successfully',
            'lesson'  => $published,
        ]);
    }

    /**
     * Archive a lesson.
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $archived = $this->lessonService->archive($request->user(), $lesson);

        return response()->json([
            'status'  => 'success',
            'message' => 'Lesson archived successfully',
            'lesson'  => $archived,
        ]);
    }

    /**
     * Soft-delete a lesson.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $this->lessonService->destroy($request->user(), $lesson);

        return response()->json([
            'status'  => 'success',
            'message' => 'Lesson deleted successfully',
        ]);
    }

    /**
     * Reorder lessons inside a module.
     */
    public function reorder(Request $request, int $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validator = Validator::make($request->all(), [
            'ordered_ids'   => 'required|array|min:1',
            'ordered_ids.*' => 'integer|exists:lessons,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->lessonService->reorder($module, $request->input('ordered_ids'));

        return response()->json([
            'status'  => 'success',
            'message' => 'Lessons reordered successfully',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Student Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * Student: View a lesson, its materials, published quizzes, and the student's progress.
     */
    public function studentShow(Request $request, int $id): JsonResponse
    {
        $student = $request->user();

        $lesson = Lesson::where('status', Lesson::PUBLISHED)
            ->with([
                'materials' => function ($q) {
                    $q->orderBy('order');
                },
                'quizzes' => function ($q) {
                    $q->where('status', 'published')
                      ->select(['id', 'lesson_id', 'title', 'description', 'pass_mark', 'timer_minutes', 'max_attempts', 'total_marks']);
                },
            ])
            ->findOrFail($id);

        $progress = LessonProgress::where('student_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        return response()->json([
            'status'   => 'success',
            'lesson'   => $lesson,
            'progress' => $progress,
        ]);
    }
}
