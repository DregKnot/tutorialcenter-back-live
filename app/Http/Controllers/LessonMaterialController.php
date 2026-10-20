<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Services\LessonMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonMaterialController extends Controller
{
    public function __construct(private LessonMaterialService $materialService)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Staff / Admin Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * List all materials for a lesson.
     */
    public function index(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);
        $materials = $this->materialService->listByLesson($lesson);

        return response()->json([
            'status'    => 'success',
            'materials' => $materials,
        ]);
    }

    /**
     * Create a material (upload file or provide URL/link).
     */
    public function store(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $validator = Validator::make($request->all(), [
            'title'            => 'required|string|max:255',
            'type'             => 'required|in:video,document,link,image,audio',
            'url'              => 'nullable|url|max:500',
            'file'             => 'nullable|file|max:102400', // max 100MB
            'duration_seconds' => 'nullable|integer|min:0',
            'order'            => 'nullable|integer|min:0',
            'is_preview'       => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($request->hasFile('file')) {
            $data['file_path'] = $this->materialService->uploadFile($lesson, $request->file('file'));
        }

        $material = $this->materialService->create($lesson, $data);

        return response()->json([
            'status'   => 'success',
            'message'  => 'Lesson material added successfully',
            'material' => $material,
        ], 201);
    }

    /**
     * View single material details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $material = LessonMaterial::findOrFail($id);

        return response()->json([
            'status'   => 'success',
            'material' => $material,
        ]);
    }

    /**
     * Update material details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $material = LessonMaterial::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'            => 'sometimes|required|string|max:255',
            'type'             => 'sometimes|required|in:video,document,link,image,audio',
            'url'              => 'nullable|url|max:500',
            'duration_seconds' => 'nullable|integer|min:0',
            'order'            => 'nullable|integer|min:0',
            'is_preview'       => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = $this->materialService->update($material, $validator->validated());

        return response()->json([
            'status'   => 'success',
            'message'  => 'Lesson material updated successfully',
            'material' => $updated,
        ]);
    }

    /**
     * Delete a material.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $material = LessonMaterial::findOrFail($id);
        $this->materialService->destroy($material);

        return response()->json([
            'status'  => 'success',
            'message' => 'Lesson material deleted successfully',
        ]);
    }

    /**
     * Reorder materials inside a lesson.
     */
    public function reorder(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $validator = Validator::make($request->all(), [
            'ordered_ids'   => 'required|array|min:1',
            'ordered_ids.*' => 'integer|exists:lesson_materials,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->materialService->reorder($lesson, $request->input('ordered_ids'));

        return response()->json([
            'status'  => 'success',
            'message' => 'Lesson materials reordered successfully',
        ]);
    }

    /**
     * Stream or download material file.
     */
    public function download(Request $request, int $id)
    {
        $material = LessonMaterial::findOrFail($id);

        if (!$material->file_path || !Storage::disk('local')->exists($material->file_path)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Material file not found',
            ], 404);
        }

        return Storage::disk('local')->download($material->file_path, $material->title);
    }
}
