<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentSubmission;
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

    public function tutorIndex(Request $request): JsonResponse
    {
        return response()->json(['assessments' => $this->service->tutorAssessments($request->user())]);
    }

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
            'questions.*.type' => 'required|in:mcq,essay',
            'questions.*.question' => 'required|string',
            'questions.*.marks' => 'required|numeric|min:0',
            'questions.*.explanation' => 'nullable|string',
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

    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        $assessment->load(['class.subject', 'questions.options']);
        return response()->json(['assessment' => $assessment]);
    }

    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'pass_mark' => 'nullable|numeric|min:0|max:100',
            'timer_minutes' => 'nullable|integer|min:1',
            'questions' => 'nullable|array|min:1',
            'questions.*.type' => 'required|in:mcq,essay',
            'questions.*.question' => 'required|string',
            'questions.*.marks' => 'required|numeric|min:0',
            'questions.*.explanation' => 'nullable|string',
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

    public function destroy(Request $request, Assessment $assessment): JsonResponse
    {
        try {
            $this->service->destroy($request->user(), $assessment);
            return response()->json(['message' => 'Assessment deleted.']);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

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

    public function submissions(Request $request, Assessment $assessment): JsonResponse
    {
        return response()->json(['submissions' => $this->service->tutorSubmissions($request->user(), $assessment)]);
    }

    public function submission(Request $request, $assessment, $submission): JsonResponse
    {
        $submission = AssessmentSubmission::findOrFail($submission);
        abort_unless($submission->assessment_id === (int) $assessment, 404);

        return response()->json(['submission' => $this->service->submissionDetail($request->user(), $submission)]);
    }

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

    public function reopen(Request $request, $assessment, $submission): JsonResponse
    {
        $submission = AssessmentSubmission::findOrFail($submission);
        abort_unless($submission->assessment_id === (int) $assessment, 404);

        $submission = $this->service->reopen($request->user(), $submission);
        return response()->json(['submission' => $submission]);
    }

    public function studentIndex(Request $request): JsonResponse
    {
        return response()->json(['assessments' => $this->service->studentAssessments($request->user())]);
    }

    public function studentShow(Request $request, Assessment $assessment): JsonResponse
    {
        try {
            return response()->json($this->service->studentAssessmentDetail($request->user(), $assessment));
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function submit(Request $request, Assessment $assessment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'answers.*.question_option_id' => 'nullable|integer',
            'answers.*.answer' => 'nullable|string',
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

    public function adminIndex(): JsonResponse
    {
        return response()->json(['assessments' => $this->service->aggregateList()]);
    }

    public function adminStats(Assessment $assessment): JsonResponse
    {
        return response()->json([
            'assessment' => $assessment->load('class.subject'),
            'stats' => $this->service->aggregateStats($assessment),
        ]);
    }

    public function advisorIndex(): JsonResponse
    {
        return response()->json(['assessments' => $this->service->aggregateList()]);
    }

    public function advisorStats(Assessment $assessment): JsonResponse
    {
        return response()->json([
            'assessment' => $assessment->load('class.subject'),
            'stats' => $this->service->aggregateStats($assessment),
        ]);
    }
}
