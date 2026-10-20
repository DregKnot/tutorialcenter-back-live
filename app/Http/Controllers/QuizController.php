<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizSubmission;
use App\Services\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QuizController extends Controller
{
    public function __construct(private QuizService $quizService)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Staff / Admin Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * List all quizzes for a lesson.
     */
    public function index(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);
        $quizzes = $this->quizService->listByLesson($lesson);

        return response()->json([
            'status'  => 'success',
            'quizzes' => $quizzes,
        ]);
    }

    /**
     * Create a quiz with its questions and answer options.
     */
    public function store(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $validator = Validator::make($request->all(), [
            'title'                         => 'required|string|max:255',
            'description'                   => 'nullable|string',
            'instructions'                  => 'nullable|string',
            'pass_mark'                     => 'nullable|numeric|min:0|max:100',
            'timer_minutes'                 => 'nullable|integer|min:1',
            'max_attempts'                  => 'nullable|integer|min:1',
            'show_answers_after_submission' => 'nullable|boolean',
            'questions'                     => 'required|array|min:1',
            'questions.*.type'              => 'required|in:multiple_choice,true_false,short_answer',
            'questions.*.question'          => 'required|string',
            'questions.*.marks'             => 'nullable|numeric|min:0',
            'questions.*.order'             => 'nullable|integer|min:0',
            'questions.*.explanation'       => 'nullable|string',
            'questions.*.image'             => 'nullable|string',
            'questions.*.options'           => 'required_if:questions.*.type,multiple_choice,true_false|array',
            'questions.*.options.*.option_text' => 'required_with:questions.*.options|string',
            'questions.*.options.*.is_correct'  => 'required_with:questions.*.options|boolean',
            'questions.*.options.*.order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $quiz = $this->quizService->create($request->user(), $lesson, $validator->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Quiz created successfully',
            'quiz'    => $quiz,
        ], 201);
    }

    /**
     * View complete quiz details (with questions and options).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::with('questions.options')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'quiz'   => $quiz,
        ]);
    }

    /**
     * Update quiz settings and optionally replace questions.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'                         => 'sometimes|required|string|max:255',
            'description'                   => 'nullable|string',
            'instructions'                  => 'nullable|string',
            'pass_mark'                     => 'nullable|numeric|min:0|max:100',
            'timer_minutes'                 => 'nullable|integer|min:1',
            'max_attempts'                  => 'nullable|integer|min:1',
            'show_answers_after_submission' => 'nullable|boolean',
            'questions'                     => 'nullable|array',
            'questions.*.type'              => 'required_with:questions|in:multiple_choice,true_false,short_answer',
            'questions.*.question'          => 'required_with:questions|string',
            'questions.*.marks'             => 'nullable|numeric|min:0',
            'questions.*.order'             => 'nullable|integer|min:0',
            'questions.*.explanation'       => 'nullable|string',
            'questions.*.image'             => 'nullable|string',
            'questions.*.options'           => 'required_if:questions.*.type,multiple_choice,true_false|array',
            'questions.*.options.*.option_text' => 'required_with:questions.*.options|string',
            'questions.*.options.*.is_correct'  => 'required_with:questions.*.options|boolean',
            'questions.*.options.*.order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = $this->quizService->update($request->user(), $quiz, $validator->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Quiz updated successfully',
            'quiz'    => $updated,
        ]);
    }

    /**
     * Publish a quiz.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::findOrFail($id);

        try {
            $published = $this->quizService->publish($request->user(), $quiz);

            return response()->json([
                'status'  => 'success',
                'message' => 'Quiz published successfully',
                'quiz'    => $published,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Archive a quiz.
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::findOrFail($id);
        $archived = $this->quizService->archive($request->user(), $quiz);

        return response()->json([
            'status'  => 'success',
            'message' => 'Quiz archived successfully',
            'quiz'    => $archived,
        ]);
    }

    /**
     * Delete a quiz.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::findOrFail($id);
        $this->quizService->destroy($request->user(), $quiz);

        return response()->json([
            'status'  => 'success',
            'message' => 'Quiz deleted successfully',
        ]);
    }

    /**
     * View all student submissions for a quiz.
     */
    public function submissions(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::findOrFail($id);
        $submissions = $this->quizService->tutorSubmissions($quiz);

        return response()->json([
            'status'      => 'success',
            'submissions' => $submissions,
        ]);
    }

    /**
     * View single student submission detail.
     */
    public function submissionDetail(Request $request, int $id, int $submissionId): JsonResponse
    {
        $quiz = Quiz::findOrFail($id);
        $submission = QuizSubmission::where('quiz_id', $quiz->id)->findOrFail($submissionId);
        $result = $this->quizService->submissionResult($quiz, $submission);

        return response()->json([
            'status' => 'success',
            'result' => $result,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Student Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * Student: List published quizzes for a lesson.
     */
    public function studentIndex(Request $request, int $lessonId): JsonResponse
    {
        $student = $request->user();

        $quizzes = Quiz::where('lesson_id', $lessonId)
            ->where('status', Quiz::PUBLISHED)
            ->withCount('questions')
            ->get()
            ->map(function ($quiz) use ($student) {
                $myAttempts = QuizSubmission::where('quiz_id', $quiz->id)
                    ->where('student_id', $student->id)
                    ->count();

                $bestScore = QuizSubmission::where('quiz_id', $quiz->id)
                    ->where('student_id', $student->id)
                    ->whereNotNull('score')
                    ->max('score');

                $hasPassed = QuizSubmission::where('quiz_id', $quiz->id)
                    ->where('student_id', $student->id)
                    ->where('passed', true)
                    ->exists();

                return [
                    'id'            => $quiz->id,
                    'title'         => $quiz->title,
                    'description'   => $quiz->description,
                    'total_marks'   => $quiz->total_marks,
                    'pass_mark'     => $quiz->pass_mark,
                    'timer_minutes' => $quiz->timer_minutes,
                    'max_attempts'  => $quiz->max_attempts,
                    'my_attempts'   => $myAttempts,
                    'best_score'    => $bestScore,
                    'has_passed'    => $hasPassed,
                ];
            });

        return response()->json([
            'status'  => 'success',
            'quizzes' => $quizzes,
        ]);
    }

    /**
     * Student: View quiz questions and start attempt.
     */
    public function studentShow(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::where('status', Quiz::PUBLISHED)->findOrFail($id);
        $data = $this->quizService->studentShow($quiz, $request->user());

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * Student: Start a new quiz attempt.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::where('status', Quiz::PUBLISHED)->findOrFail($id);

        try {
            $submission = $this->quizService->startAttempt($quiz, $request->user());

            return response()->json([
                'status'     => 'success',
                'message'    => 'Quiz attempt started',
                'submission' => $submission,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Student: Submit quiz answers.
     */
    public function submit(Request $request, int $id, int $submissionId): JsonResponse
    {
        $quiz = Quiz::where('status', Quiz::PUBLISHED)->findOrFail($id);
        $submission = QuizSubmission::findOrFail($submissionId);

        $validator = Validator::make($request->all(), [
            'answers'                        => 'required|array|min:1',
            'answers.*.question_id'          => 'required|integer|exists:quiz_questions,id',
            'answers.*.selected_option_id'   => 'nullable|integer|exists:quiz_question_options,id',
            'answers.*.text_answer'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $graded = $this->quizService->submit($quiz, $submission, $request->user(), $validator->validated());

            return response()->json([
                'status'     => 'success',
                'message'    => 'Quiz submitted successfully',
                'submission' => $graded,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Student: View personal submission results for a quiz.
     */
    public function mySubmissions(Request $request, int $id): JsonResponse
    {
        $student = $request->user();
        $quiz = Quiz::findOrFail($id);

        $submissions = QuizSubmission::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->with(['answers.question', 'answers.selectedOption'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status'      => 'success',
            'submissions' => $submissions,
        ]);
    }
}
