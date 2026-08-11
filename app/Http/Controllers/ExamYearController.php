<?php

namespace App\Http\Controllers;

use App\Models\ExamBody;
use App\Models\ExamYear;
use App\Models\PastQuestion;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Services\AdminNotificationService;

class ExamYearController extends Controller
{
    // Retrieve a list of exam years with optional filtering
    public function index(Request $request)
    {
        $query = ExamYear::with(['examBody.course', 'subject']);

        if ($request->filled('exam_body_id')) {
            $query->where('exam_body_id', $request->exam_body_id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $examYears = $query->latest()->paginate(20);

        return response()->json($examYears);
    }

    // Store a new exam year
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_body_id' => ['required', 'exists:exam_bodies,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'year' => [
                'required',
                'integer',
                'digits:4',
                'min:1980',
                'max:' . now()->year,
                Rule::unique('exam_years')->where(function ($query) use ($request) {
                    return $query
                        ->where('exam_body_id', $request->exam_body_id)
                        ->where('subject_id', $request->subject_id)
                        ->where('year', $request->year);
                }),
            ],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $examYear = ExamYear::create([
            'exam_body_id' => $request->exam_body_id,
            'subject_id' => $request->subject_id,
            'year' => $request->year,
            'status' => $request->status ?? 'active',
        ]);

        AdminNotificationService::notify(
            'exam_year_created',
            "Exam year created: {$examYear->year} for subject ID: {$examYear->subject_id} and exam body ID: {$examYear->exam_body_id} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
            ['exam_year_id' => $examYear->id]
        );

        return response()->json([
            'message' => 'Exam year created successfully.',
            'data' => $examYear->load(['examBody.course', 'subject']),
        ], 201);
    }

    // Show a specific exam year
    public function show(ExamYear $examYear, $id)
    {
        try {
            $examYear = ExamYear::findOrFail($id);
            return response()->json(
                $examYear->load(['examBody.course', 'subject'])
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized to view exam year.',
            ], 403);
        }
    }

    // Update an existing exam year
    public function update(Request $request, $id)
    {
        $examYear = ExamYear::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'exam_body_id' => ['required', 'exists:exam_bodies,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'year' => [
                'required',
                'integer',
                'digits:4',
                'min:1980',
                'max:' . now()->year,
                Rule::unique('exam_years')->where(function ($query) use ($request) {
                    return $query
                        ->where('exam_body_id', $request->exam_body_id)
                        ->where('subject_id', $request->subject_id)
                        ->where('year', $request->year);
                })->ignore($examYear->id),
            ],
            'status' => ['required', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $examYear->update([
            'exam_body_id' => $request->exam_body_id,
            'subject_id' => $request->subject_id,
            'year' => $request->year,
            'status' => $request->status ?? $examYear->status,
        ]);

        AdminNotificationService::notify(
            'exam_year_updated',
            "Exam year updated: {$examYear->year} for subject ID: {$examYear->subject_id} and exam body ID: {$examYear->exam_body_id} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
            ['exam_year_id' => $examYear->id]
        );

        return response()->json([
            'message' => 'Exam year updated successfully.',
            'examYear' => $examYear->load(['examBody.course', 'subject']),
        ]);
    }

    // Delete an exam year
    public function destroy(ExamYear $examYear, Request $request, $id)
    {
        try {
            $examYear = ExamYear::findOrFail($id);
            $examYear->delete();

            AdminNotificationService::notify(
                'exam_year_deleted',
                "Exam year deleted: {$examYear->year} for subject ID: {$examYear->subject_id} and exam body ID: {$examYear->exam_body_id} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['exam_year_id' => $examYear->id]
            );

            return response()->json([
                'message' => 'Exam year deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized to delete exam year.',
            ], 403);
        }
    }









    // Retrieve a list of exam bodies with the count of associated exam years
    public function examBodies()
    {
        return response()->json(
            ExamBody::withCount('examYears')
                ->orderBy('name')
                ->get()
        );
    }

    // Retrieve a list of subjects with the count of associated exam years, optionally filtered by exam body
    public function subjects(Request $request)
    {
        $query = Subject::query();

        if ($request->filled('exam_body_id')) {

            $query->whereHas('examYears', function ($q) use ($request) {
                $q->where('exam_body_id', $request->exam_body_id);
            });

            $query->withCount([
                'examYears as exam_years_count' => function ($q) use ($request) {
                    $q->where('exam_body_id', $request->exam_body_id);
                }
            ]);
        } else {

            $query->withCount('examYears');
        }

        return response()->json(
            $query->orderBy('name')->get()
        );
    }

    // Retrieve a list of exam years with optional filtering by exam body and subject
    public function years(Request $request)
    {
        $query = ExamYear::with('examBody', 'subject');

        if ($request->filled('exam_body_id')) {
            $query->where('exam_body_id', $request->exam_body_id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        return response()->json(
            $query->orderByDesc('year')->get()
        );
    }

    // Retrieve a paginated list of past questions for a specific exam year
    public function questions(Request $request)
    {
        return response()->json(
            PastQuestion::with('options', 'files', 'group')
                ->where('exam_year_id', $request->exam_year_id)
                ->orderBy('question_number')
                ->paginate(50)
        );
    }
}
