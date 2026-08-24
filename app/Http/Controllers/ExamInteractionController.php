<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Models\PastQuestion;
use App\Models\PastQuestionOption;
use App\Services\ExamInteractionService;
use App\Services\LearningStreakService;
use App\Services\OnboardingAchievementService;
use App\Services\PracticeMilestoneService;
use Illuminate\Http\Request;

class ExamInteractionController extends Controller
{
    public function __construct(
        protected ExamInteractionService $interactionService,
        protected OnboardingAchievementService $onboardingAchievementService,
        protected PracticeMilestoneService $practiceMilestoneService,
        protected LearningStreakService $learningStreakService
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

        $firstAnswerAward = $this->onboardingAchievementService->firstAnswerSubmitted(
            $request->user(),
            $attempt,
            $interaction
        );

        $milestoneAwards = $this->practiceMilestoneService
            ->recordInteraction($interaction);
        $this->learningStreakService->recordActivity(
            $request->user(),
            $interaction->action_submitted_at
        );

        return response()->json([
            'success' => true,
            'data' => $interaction,
            'new_achievements' => $this->formatAchievements(array_merge(
                $firstAnswerAward?->wasRecentlyCreated ? [$firstAnswerAward] : [],
                $milestoneAwards
            )),
        ]);
    }

    private function formatAchievements(array $awards): array
    {
        return collect($awards)
            ->filter(fn ($award) => $award?->wasRecentlyCreated)
            ->map(function ($award) {
                $award->loadMissing('achievement');

                return [
                    'id' => $award->id,
                    'code' => $award->achievement?->code,
                    'name' => $award->achievement?->name,
                    'category' => $award->achievement?->category,
                    'type' => $award->achievement?->type,
                    'tier' => $award->tier,
                    'awarded_at' => $award->awarded_at,
                ];
            })->values()->all();
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
