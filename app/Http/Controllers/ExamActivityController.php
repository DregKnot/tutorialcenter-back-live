<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Models\PastQuestion;
use App\Services\ExamActivityService;
use Illuminate\Http\Request;

class ExamActivityController extends Controller
{
    public function __construct(
        protected ExamActivityService $activityService
    ) {}

    public function start(Request $request, ExamAttempt $attempt)
    {
        $data = $request->validate([
            'session_token' => ['required', 'uuid'],
            'metadata' => ['nullable', 'array'],
        ]);

        $session = $this->activityService->startSession(
            $request->user(),
            $attempt,
            $data['session_token'],
            $data['metadata'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }

    public function heartbeat(Request $request, ExamAttempt $attempt)
    {
        $data = $request->validate([
            'session_token' => ['required', 'uuid'],
            'client_event_id' => ['required', 'uuid'],
            'question_id' => ['nullable', 'integer', 'exists:past_questions,id'],
            'page_visible' => ['required', 'boolean'],
            'app_focused' => ['required', 'boolean'],
            'interaction_type' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        $question = isset($data['question_id'])
            ? PastQuestion::findOrFail($data['question_id'])
            : null;

        $heartbeat = $this->activityService->recordHeartbeat(
            $request->user(),
            $attempt,
            $data['session_token'],
            $data['client_event_id'],
            $data['page_visible'],
            $data['app_focused'],
            $question,
            $data['interaction_type'] ?? null,
            $data['occurred_at'] ?? null,
            $data['metadata'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => $heartbeat,
        ]);
    }

    public function end(Request $request, ExamAttempt $attempt)
    {
        $data = $request->validate([
            'session_token' => ['required', 'uuid'],
            'reason' => [
                'nullable',
                'string',
                'in:completed,submitted,abandoned,hidden,backgrounded,disconnected,idle',
            ],
        ]);

        $session = $this->activityService->endSession(
            $request->user(),
            $attempt,
            $data['session_token'],
            $data['reason'] ?? 'completed'
        );

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }
}
