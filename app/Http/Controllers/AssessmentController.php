<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Student;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AssessmentController extends Controller
{
    public function __construct(private AssessmentService $service)
    {
    }

    /**
     * List assessments for the authenticated tutor.

     * Returns all assessments the tutor created or is assigned to, each with
     * aggregate submission stats for the tutor dashboard.
     */
    public function tutorIndex(Request $request): JsonResponse
    {
        return response()->json(['assessments' => $this->service->tutorAssessments($request->user())]);
    }

    /**
     * Create a new draft assessment with questions.

     * Supports question types: mcq, essay and paper_submission. MCQ questions must
     * have exactly one correct option. Created assessments are drafts and must be
     * published before students can submit.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'pass_mark' => 'nullable|numeric|min:0|max:100',
            'timer_minutes' => 'nullable|integer|min:1',
            'questions' => 'required|array|min:1',
            'questions.*.type' => 'required|in:mcq,essay,paper_submission',
            'questions.*.question' => 'required|string',
            'questions.*.marks' => 'required|numeric|min:0',
            'questions.*.explanation' => 'nullable|string',
            'questions.*.model_image' => 'nullable|string',
            'questions.*.options' => 'required_if:questions.*.type,mcq|array|min:2',
            'questions.*.options.*.option_text' => 'required_with:questions.*.options|string',
            'questions.*.options.*.is_correct' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $assessment = $this->service->create($request->user(), $validator->validated());
            return response()->json(['assessment' => $assessment], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Show a single assessment with its questions and options.
     */
    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        $assessment->load(['class.subject', 'questions.options']);
        return response()->json(['assessment' => $assessment]);
    }

    /**
     * Update a draft assessment and optionally replace its questions.
     */
    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'pass_mark' => 'nullable|numeric|min:0|max:100',
            'timer_minutes' => 'nullable|integer|min:1',
            'questions' => 'nullable|array|min:1',
            'questions.*.type' => 'required|in:mcq,essay,paper_submission',
            'questions.*.question' => 'required|string',
            'questions.*.marks' => 'required|numeric|min:0',
            'questions.*.explanation' => 'nullable|string',
            'questions.*.model_image' => 'nullable|string',
            'questions.*.options' => 'required_if:questions.*.type,mcq|array|min:2',
            'questions.*.options.*.option_text' => 'required_with:questions.*.options|string',
            'questions.*.options.*.is_correct' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $assessment = $this->service->update($request->user(), $assessment, $validator->validated());
            return response()->json(['assessment' => $assessment]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Delete (soft-delete) a draft assessment.
     */
    public function destroy(Request $request, Assessment $assessment): JsonResponse
    {
        try {
            $this->service->destroy($request->user(), $assessment);
            return response()->json(['message' => 'Assessment deleted.']);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Publish an assessment for a date window and notify enrolled students.

     * Sets status to published, stores the opens/due window, and notifies (in-app
     * + email) every student enrolled in the assessment's subject.
     */
    public function publish(Request $request, Assessment $assessment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'opens_at' => 'nullable|date',
            'due_at' => 'required|date|after_or_equal:opens_at',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $data = $validator->validated();
            $assessment = $this->service->publish(
                $request->user(),
                $assessment,
                $data['opens_at'] ?? null,
                $data['due_at']
            );
            return response()->json(['assessment' => $assessment]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * List all student submissions for an assessment.
     */
    public function submissions(Request $request, Assessment $assessment): JsonResponse
    {
        return response()->json(['submissions' => $this->service->tutorSubmissions($request->user(), $assessment)]);
    }

    /**
     * Show one submission in full detail for grading.

     * Includes the student, every answer and attached files (with signed URLs).
     */
    public function submission(Request $request, $assessment, $submission): JsonResponse
    {
        $submission = AssessmentSubmission::findOrFail($submission);
        abort_unless($submission->assessment_id === (int) $assessment, 404);

        return response()->json(['submission' => $this->service->submissionDetail($request->user(), $submission)]);
    }

    /**
     * Grade a submission by awarding per-question marks and feedback.

     * Keys are question IDs. Re-calling this re-grades. Status becomes 'graded'
     * and the student is notified with their score.
     */
    public function grade(Request $request, $assessment, $submission): JsonResponse
    {
        $submission = AssessmentSubmission::findOrFail($submission);
        abort_unless($submission->assessment_id === (int) $assessment, 404);

        $validator = Validator::make($request->all(), [
            'grades' => 'required|array',
            'grades.*.marks_awarded' => 'nullable|numeric|min:0',
            'grades.*.feedback' => 'nullable|string',
            'grades.*.is_correct' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $submission = $this->service->grade($request->user(), $submission, $validator->validated());
            return response()->json(['submission' => $submission]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Reopen a submission so the student can attempt it again.

     * Resets the submission to in_progress and clears its answers.
     */
    public function reopen(Request $request, $assessment, $submission): JsonResponse
    {
        $submission = AssessmentSubmission::findOrFail($submission);
        abort_unless($submission->assessment_id === (int) $assessment, 404);

        $submission = $this->service->reopen($request->user(), $submission);
        return response()->json(['submission' => $submission]);
    }

    /**
     * List assessments assigned to the authenticated student.

     * Only published, opened assessments in subjects the student is enrolled in,
     * each with the student's own submission state.
     */
    public function studentIndex(Request $request): JsonResponse
    {
        return response()->json(['assessments' => $this->service->studentAssessments($request->user())]);
    }

    /**
     * Show assessment detail to the student.

     * MCQ correct options and essay/paper explanations are hidden until the
     * student submits, to prevent answer leakage.
     */
    public function studentShow(Request $request, Assessment $assessment): JsonResponse
    {
        try {
            return response()->json($this->service->studentAssessmentDetail($request->user(), $assessment));
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Submit the authenticated student's answers for an assessment.

     * MCQ answers are auto-graded; essays and paper submissions are graded later
     * by the tutor. Blocks late or duplicate submissions.
     */
    public function submit(Request $request, Assessment $assessment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*.question_option_id' => 'nullable|integer',
            'answers.*.answer' => 'nullable|string',
            'answers.*.file_paths' => 'nullable|array|max:5',
            'answers.*.file_paths.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $submission = $this->service->submit($request->user(), $assessment, $validator->validated());
            return response()->json(['submission' => $submission], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Upload assessment images (student A4 work or tutor model image).

     * Stores images privately under assessment-uploads and returns their paths,
     * which are then referenced at submit/create time. Validates image type and size.
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'images' => 'required|array|min:1|max:5',
            'images.*' => 'required|image|mimes:jpeg,jpg,png,webp,heic,heif|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $folder = $user instanceof Student ? 'student-' . $user->id : 'staff-' . $user->id;

        try {
            $paths = $this->service->storeUploads($folder, $validator->validated()['images']);
            return response()->json(['files' => $paths], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Upload failed.'], 500);
        }
    }

    /**
     * List all assessments with aggregate stats (read-only, admin).
     */
    public function adminIndex(): JsonResponse
    {
        return response()->json(['assessments' => $this->service->aggregateList()]);
    }

    /**
     * Aggregate-only stats for a single assessment (read-only, admin).
     */
    public function adminStats(Assessment $assessment): JsonResponse
    {
        return response()->json([
            'assessment' => $assessment->load('class.subject'),
            'stats' => $this->service->aggregateStats($assessment),
        ]);
    }

    /**
     * List all assessments with aggregate stats (read-only, advisor).
     */
    public function advisorIndex(): JsonResponse
    {
        return response()->json(['assessments' => $this->service->aggregateList()]);
    }

    /**
     * Aggregate-only stats for a single assessment (read-only, advisor).
     */
    public function advisorStats(Assessment $assessment): JsonResponse
    {
        return response()->json([
            'assessment' => $assessment->load('class.subject'),
            'stats' => $this->service->aggregateStats($assessment),
        ]);
    }
}
