<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Models\PastQuestion;
use App\Models\PastQuestionOption;
use App\Services\ExamInteractionService;
use App\Services\OnboardingAchievementService;
use App\Services\PracticeMilestoneService;
use Illuminate\Http\Request;

class ExamInteractionController extends Controller
{
    public function __construct(
        protected ExamInteractionService $interactionService,
        protected OnboardingAchievementService $onboardingAchievementService,
        protected PracticeMilestoneService $practiceMilestoneService
    ) {}

    public function viewed(
        Request $request,
        ExamAttempt $attempt,
        PastQuestion $question
    ) {
        $data = $request->validate([
            'sequence_number' => ['required', 'integer', 'min:1'],
            'client_event_id' => ['required', 'uuid'],
            'metadata' => ['nullable', 'array'],
        ]);

        $interaction = $this->interactionService->recordViewed(
            $request->user(),
            $attempt,
            $question,
            $data['sequence_number'],
            $data['client_event_id'],
            $data['metadata'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => $interaction,
        ]);
    }

    public function answered(
        Request $request,
        ExamAttempt $attempt,
        PastQuestion $question
    ) {
        $data = $request->validate([
            'option_id' => ['required', 'integer', 'exists:past_question_options,id'],
            'sequence_number' => ['required', 'integer', 'min:1'],
            'client_event_id' => ['required', 'uuid'],
            'metadata' => ['nullable', 'array'],
        ]);

        $interaction = $this->interactionService->recordAnswered(
            $request->user(),
            $attempt,
            $question,
            PastQuestionOption::findOrFail($data['option_id']),
            $data['sequence_number'],
            $data['client_event_id'],
            $data['metadata'] ?? []
        );

        $this->onboardingAchievementService->firstAnswerSubmitted(
            $request->user(),
            $attempt,
            $interaction
        );

        $this->practiceMilestoneService->recordInteraction($interaction);

        return response()->json([
            'success' => true,
            'data' => $interaction,
        ]);
    }

    public function skipped(
        Request $request,
        ExamAttempt $attempt,
        PastQuestion $question
    ) {
        $data = $request->validate([
            'sequence_number' => ['required', 'integer', 'min:1'],
            'client_event_id' => ['required', 'uuid'],
            'metadata' => ['nullable', 'array'],
        ]);

        $interaction = $this->interactionService->recordSkipped(
            $request->user(),
            $attempt,
            $question,
            $data['sequence_number'],
            $data['client_event_id'],
            $data['metadata'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => $interaction,
        ]);
    }
}
