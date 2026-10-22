<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Module;
use Illuminate\Support\Collection;

class LessonService
{
    /**
     * List all lessons within a module.
     */
    public function listByModule(Module $module): Collection
    {
        return $module->lessons()
            ->withCount(['materials', 'quizzes'])
            ->get();
    }

    /**
     * Create a new lesson inside a module.
     */
    public function create($staff, Module $module, array $data): Lesson
    {
        return $module->lessons()->create([
            'created_by'       => $staff->id,
            'title'            => $data['title'],
            'description'      => $data['description'] ?? null,
            'order'            => $data['order'] ?? 0,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'status'           => $data['status'] ?? Lesson::PUBLISHED,
        ]);
    }

    /**
     * Update a lesson.
     */
    public function update($staff, Lesson $lesson, array $data): Lesson
    {
        $lesson->update([
            'title'            => $data['title'] ?? $lesson->title,
            'description'      => $data['description'] ?? $lesson->description,
            'order'            => $data['order'] ?? $lesson->order,
            'duration_minutes' => $data['duration_minutes'] ?? $lesson->duration_minutes,
            'status'           => $data['status'] ?? $lesson->status,
        ]);

        return $lesson->fresh();
    }

    /**
     * Publish a lesson.
     */
    public function publish($staff, Lesson $lesson): Lesson
    {
        $lesson->update(['status' => Lesson::PUBLISHED]);
        return $lesson->fresh();
    }

    /**
     * Archive a lesson.
     */
    public function archive($staff, Lesson $lesson): Lesson
    {
        $lesson->update(['status' => Lesson::ARCHIVED]);
        return $lesson->fresh();
    }

    /**
     * Soft-delete a lesson.
     */
    public function destroy($staff, Lesson $lesson): void
    {
        $lesson->delete();
    }

    /**
     * Show a lesson with its materials and quizzes.
     */
    public function detail(Lesson $lesson): Lesson
    {
        return $lesson->load(['materials', 'quizzes.questions.options']);
    }

    /**
     * Reorder lessons within a module by providing an ordered array of IDs.
     */
    public function reorder(Module $module, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $lessonId) {
            Lesson::where('id', $lessonId)
                ->where('module_id', $module->id)
                ->update(['order' => $position + 1]);
        }
    }
}
