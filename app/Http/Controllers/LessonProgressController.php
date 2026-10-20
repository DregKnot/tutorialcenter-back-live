<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Services\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LessonProgressController extends Controller
{
    public function __construct(private LessonProgressService $progressService)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Student Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * Student: Update progress on a lesson (time spent, completion percentage, or heartbeat).
     */
    public function update(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $validator = Validator::make($request->all(), [
            'time_spent_seconds'    => 'nullable|integer|min:0',
            'completion_percentage' => 'nullable|integer|min:0|max:100',
            'status'                => 'nullable|in:not_started,in_progress,completed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $progress = $this->progressService->updateProgress($request->user(), $lesson, $validator->validated());

        return response()->json([
            'status'   => 'success',
            'message'  => 'Lesson progress updated',
            'progress' => $progress,
        ]);
    }

    /**
     * Student: Mark a lesson as fully completed.
     */
    public function complete(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);
        $progress = $this->progressService->completeLesson($request->user(), $lesson);

        return response()->json([
            'status'   => 'success',
            'message'  => 'Lesson marked as completed',
            'progress' => $progress,
        ]);
    }

    /**
     * Student: View overall progress for an entire subject (modules, lessons, completion status).
     */
    public function subjectProgress(Request $request, int $subjectId): JsonResponse
    {
        $subject = Subject::findOrFail($subjectId);
        $overview = $this->progressService->getStudentSubjectProgress($request->user(), $subject);

        return response()->json([
            'status'   => 'success',
            'progress' => $overview,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Staff / Admin Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * Staff/Admin: View a specific student's progress across a subject.
     */
    public function studentProgress(Request $request, int $studentId, int $subjectId): JsonResponse
    {
        $student = Student::findOrFail($studentId);
        $subject = Subject::findOrFail($subjectId);

        $progress = $this->progressService->getStudentSubjectProgress($student, $subject);

        return response()->json([
            'status'   => 'success',
            'student'  => $student->only(['id', 'firstname', 'lastname', 'email']),
            'progress' => $progress,
        ]);
    }

    /**
     * Staff/Admin: View aggregate lesson engagement and completion statistics for a subject.
     */
    public function subjectOverview(Request $request, int $subjectId): JsonResponse
    {
        $subject = Subject::findOrFail($subjectId);
        $overview = $this->progressService->getSubjectOverviewForStaff($subject);

        return response()->json([
            'status'   => 'success',
            'overview' => $overview,
        ]);
    }
}
