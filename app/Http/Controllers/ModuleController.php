<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Subject;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    public function __construct(private ModuleService $moduleService)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Staff / Admin Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * List all modules for a given subject.
     */
    public function index(Request $request, int $subjectId): JsonResponse
    {
        $subject = Subject::findOrFail($subjectId);
        $modules = $this->moduleService->listBySubject($subject);

        return response()->json([
            'status'  => 'success',
            'modules' => $modules,
        ]);
    }

    /**
     * Create a new module under a subject.
     */
    public function store(Request $request, int $subjectId): JsonResponse
    {
        $subject = Subject::findOrFail($subjectId);

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $module = $this->moduleService->create($request->user(), $subject, $validator->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Module created successfully',
            'module'  => $module,
        ], 201);
    }

    /**
     * View a specific module with its lessons.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $module = Module::with(['lessons' => function ($q) {
            $q->orderBy('order');
        }])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'module' => $module,
        ]);
    }

    /**
     * Update module details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $module = Module::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = $this->moduleService->update($request->user(), $module, $validator->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Module updated successfully',
            'module'  => $updated,
        ]);
    }

    /**
     * Publish a module.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $module = Module::findOrFail($id);
        $published = $this->moduleService->publish($request->user(), $module);

        return response()->json([
            'status'  => 'success',
            'message' => 'Module published successfully',
            'module'  => $published,
        ]);
    }

    /**
     * Archive a module.
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $module = Module::findOrFail($id);
        $archived = $this->moduleService->archive($request->user(), $module);

        return response()->json([
            'status'  => 'success',
            'message' => 'Module archived successfully',
            'module'  => $archived,
        ]);
    }

    /**
     * Soft delete a module.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $module = Module::findOrFail($id);
        $this->moduleService->destroy($request->user(), $module);

        return response()->json([
            'status'  => 'success',
            'message' => 'Module deleted successfully',
        ]);
    }

    /**
     * Restore a soft-deleted module.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $module = $this->moduleService->restore($id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Module restored successfully',
            'module'  => $module,
        ]);
    }

    /**
     * Reorder modules within a subject.
     */
    public function reorder(Request $request, int $subjectId): JsonResponse
    {
        $subject = Subject::findOrFail($subjectId);

        $validator = Validator::make($request->all(), [
            'ordered_ids'   => 'required|array|min:1',
            'ordered_ids.*' => 'integer|exists:modules,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->moduleService->reorder($subject, $request->input('ordered_ids'));

        return response()->json([
            'status'  => 'success',
            'message' => 'Modules reordered successfully',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Student Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * List published modules for a subject for students.
     */
    public function studentIndex(Request $request, int $subjectId): JsonResponse
    {
        $subject = Subject::findOrFail($subjectId);

        $modules = $subject->modules()
            ->where('status', Module::PUBLISHED)
            ->with(['lessons' => function ($q) {
                $q->where('status', 'published')->orderBy('order');
            }])
            ->orderBy('order')
            ->get();

        return response()->json([
            'status'  => 'success',
            'modules' => $modules,
        ]);
    }

    /**
     * View a single published module for students.
     */
    public function studentShow(Request $request, int $id): JsonResponse
    {
        $module = Module::where('status', Module::PUBLISHED)
            ->with(['lessons' => function ($q) {
                $q->where('status', 'published')->orderBy('order');
            }])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'module' => $module,
        ]);
    }
}
