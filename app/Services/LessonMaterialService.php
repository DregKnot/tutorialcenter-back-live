<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonMaterial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class LessonMaterialService
{
    /**
     * List all materials for a lesson.
     */
    public function listByLesson(Lesson $lesson): Collection
    {
        return $lesson->materials;
    }

    /**
     * Attach a new material to a lesson.
     */
    public function create(Lesson $lesson, array $data): LessonMaterial
    {
        return $lesson->materials()->create([
            'title'            => $data['title'],
            'type'             => $data['type'],
            'url'              => $data['url'] ?? null,
            'file_path'        => $data['file_path'] ?? null,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'order'            => $data['order'] ?? 0,
            'is_preview'       => $data['is_preview'] ?? false,
        ]);
    }

    /**
     * Upload a file and store it under lesson-materials/{lesson_id}/.
     * Returns the stored path for saving in the DB.
     */
    public function uploadFile(Lesson $lesson, UploadedFile $file): string
    {
        return $file->store("lesson-materials/{$lesson->id}", 'local');
    }

    /**
     * Update a material record.
     */
    public function update(LessonMaterial $material, array $data): LessonMaterial
    {
        $material->update([
            'title'            => $data['title'] ?? $material->title,
            'type'             => $data['type'] ?? $material->type,
            'url'              => $data['url'] ?? $material->url,
            'duration_seconds' => $data['duration_seconds'] ?? $material->duration_seconds,
            'order'            => $data['order'] ?? $material->order,
            'is_preview'       => $data['is_preview'] ?? $material->is_preview,
        ]);

        return $material->fresh();
    }

    /**
     * Soft-delete a material. Also removes the stored file if it exists.
     */
    public function destroy(LessonMaterial $material): void
    {
        if ($material->file_path && Storage::disk('local')->exists($material->file_path)) {
            Storage::disk('local')->delete($material->file_path);
        }

        $material->delete();
    }

    /**
     * Reorder materials within a lesson.
     */
    public function reorder(Lesson $lesson, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $materialId) {
            LessonMaterial::where('id', $materialId)
                ->where('lesson_id', $lesson->id)
                ->update(['order' => $position + 1]);
        }
    }
}
