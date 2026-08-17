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
            'contact' => ['nullable', 'string', 'max:255', 'unique:cognitive_tests,contact'],
            'school' => ['required', 'string', 'max:255'],
        ]);

        // Prevent retakes by the same student from the same school
        $existing = CognitiveTest::whereRaw('LOWER(student_name) = ?', [strtolower(trim($validated['student_name']))])
            ->whereRaw('LOWER(school) = ?', [strtolower(trim($validated['school']))])
            ->whereNotNull('score')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A student with this name from this school has already taken this test.',
                'errors' => [
                    'student_name' => ['A student with this name from this school has already completed the test.']
                ]
            ], 422);
        }

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
