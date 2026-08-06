<?php

namespace App\Http\Controllers;

use App\Models\CognitiveTest;
use Illuminate\Http\Request;

class CognitiveTestController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => CognitiveTest::latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:255'],
            'school' => ['required', 'string', 'max:255'],
        ]);

        $result = CognitiveTest::create($validated);

        return response()->json([
            'message' => 'Cognitive test started successfully.',
            'data' => $result,
        ], 201);
    }

    public function complete(Request $request, CognitiveTest $cognitiveTest)
    {
        $validated = $request->validate([
            'score' => ['required', 'integer', 'between:0,20'],
        ]);

        $cognitiveTest->update([
            'test_ended_at' => now(),
            'score' => $validated['score'],
        ]);

        return response()->json([
            'message' => 'Cognitive test result saved successfully.',
            'data' => $cognitiveTest->fresh(),
        ]);
    }
}
