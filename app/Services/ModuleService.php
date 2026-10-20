<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ModuleService
{
    /**
     * List all modules under a given subject.
     */
    public function listBySubject(Subject $subject): Collection
    {
        return $subject->modules()
            ->withCount('lessons')
            ->get();
    }

    /**
     * Create a new module under a subject.
     */
    public function create($staff, Subject $subject, array $data): Module
    {
        return $subject->modules()->create([
            'created_by'  => $staff->id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'order'       => $data['order'] ?? 0,
            'status'      => Module::DRAFT,
        ]);
    }

    /**
     * Update an existing module.
     */
    public function update($staff, Module $module, array $data): Module
    {
        $module->update([
            'title'       => $data['title'] ?? $module->title,
            'description' => $data['description'] ?? $module->description,
            'order'       => $data['order'] ?? $module->order,
        ]);

        return $module->fresh();
    }

    /**
     * Publish a module so students can see it.
     */
    public function publish($staff, Module $module): Module
    {
        $module->update(['status' => Module::PUBLISHED]);
        return $module->fresh();
    }

    /**
     * Archive a module (hide without deleting).
     */
    public function archive($staff, Module $module): Module
    {
        $module->update(['status' => Module::ARCHIVED]);
        return $module->fresh();
    }

    /**
     * Soft-delete a module.
     */
    public function destroy($staff, Module $module): void
    {
        $module->delete();
    }

    /**
     * Restore a soft-deleted module.
     */
    public function restore(int $id): Module
    {
        $module = Module::withTrashed()->findOrFail($id);
        $module->restore();
        return $module;
    }

    /**
     * Reorder modules within a subject by providing an ordered array of IDs.
     */
    public function reorder(Subject $subject, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $moduleId) {
            Module::where('id', $moduleId)
                ->where('subject_id', $subject->id)
                ->update(['order' => $position + 1]);
        }
    }
}
