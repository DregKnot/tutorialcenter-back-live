<?php

namespace App\Http\Controllers;

use AddressInfo;
use App\Models\ExamBody;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\AdminNotificationService;

class ExamBodyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $query = ExamBody::query();

            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $examBodies = $query
                ->latest()
                ->paginate(20);

            return response()->json($examBodies);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized to view exam bodies.',
            ], 403);
        }
    }

    /**
     * Store a newly created resource.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'unique:exam_bodies,name'],
            'course_id' => ['required', 'exists:courses,id'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            $examBody = ExamBody::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'course_id' => $request->course_id,
                'status' => $request->status ?? 'active',
            ]);

            AdminNotificationService::notify(
                'exam_body_created',
                "Exam body created: {$examBody->name} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['exam_body_id' => $examBody->id]
            );

            return response()->json([
                'message' => 'Exam body created successfully.',
                'data' => $examBody,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized to create exam body.',
            ], 403);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ExamBody $examBody)
    {
        try {
            $examBody->load(['course', 'examYears']); // Load related course and exam years
            return response()->json($examBody);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized to view exam body.',
            ], 403);
        }
    }

    /**
     * Update the specified resource.
     */
    // public function update(Request $request, ExamBody $examBody)
    public function update(Request $request, $id)
    {
        $examBody = ExamBody::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:exam_bodies,name,' . $examBody->id,
            ],
            'course_id' => ['required', 'exists:courses,id'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {

            $examBody->update([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'course_id' => $request->course_id,
                'status' => $request->status ?? $examBody->status,
            ]);
            $examBody->refresh();

            AdminNotificationService::notify(
                'exam_body_updated',
                "Exam body updated: {$examBody->name} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['exam_body_id' => $examBody->id]
            );

            return response()->json([
                'message' => 'Exam body updated successfully.',
                'examBody' => $examBody,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized to update exam body.',
            ], 403);
        }
    }

    /**
     * Remove the specified resource.
     */
    public function destroy(ExamBody $examBody, Request $request, $id)
    {
        try {
            $examBody = ExamBody::findOrFail($id);
            $examBody->delete();

            AdminNotificationService::notify(
                'exam_body_deleted',
                "Exam body deleted: {$examBody->name} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['exam_body_id' => $examBody->id]
            );

            return response()->json([
                'message' => 'Exam body deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized to delete exam body.',
            ], 403);
        }
    }
}
