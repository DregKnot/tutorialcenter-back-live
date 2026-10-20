<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Module;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LessonProgressService
{
    /**
     * Record or update progress for a student on a specific lesson.
     */
    public function updateProgress(Student $student, Lesson $lesson, array $data): LessonProgress
    {
        $module = $lesson->module;
        $subjectId = $module ? $module->subject_id : null;

        $progress = LessonProgress::firstOrNew([
            'student_id' => $student->id,
            'lesson_id'  => $lesson->id,
        ]);

        $progress->module_id = $lesson->module_id;
        $progress->subject_id = $subjectId;

        if (!$progress->exists) {
            $progress->started_at = now();
            $progress->status = LessonProgress::IN_PROGRESS;
        }

        if (isset($data['time_spent_seconds'])) {
            $progress->time_spent_seconds = ($progress->time_spent_seconds ?? 0) + (int) $data['time_spent_seconds'];
        }

        if (isset($data['completion_percentage'])) {
            $progress->completion_percentage = max(
                $progress->completion_percentage ?? 0,
                min(100, (int) $data['completion_percentage'])
            );
        }

        // If explicitly set to completed or percentage is 100%
        if (($data['status'] ?? null) === LessonProgress::COMPLETED || ($progress->completion_percentage >= 100)) {
            $progress->status = LessonProgress::COMPLETED;
            $progress->completion_percentage = 100;
            if (!$progress->completed_at) {
                $progress->completed_at = now();
            }
        } elseif (($data['status'] ?? null) === LessonProgress::IN_PROGRESS) {
            $progress->status = LessonProgress::IN_PROGRESS;
        }

        $progress->save();

        return $progress;
    }

    /**
     * Mark a lesson as completed directly.
     */
    public function completeLesson(Student $student, Lesson $lesson): LessonProgress
    {
        return $this->updateProgress($student, $lesson, [
            'status' => LessonProgress::COMPLETED,
            'completion_percentage' => 100,
        ]);
    }

    /**
     * Get a student's progress across all lessons in a subject.
     */
    public function getStudentSubjectProgress(Student $student, Subject $subject): array
    {
        $modules = $subject->modules()
            ->where('status', Module::PUBLISHED)
            ->with(['lessons' => function ($q) {
                $q->where('status', Lesson::PUBLISHED)->orderBy('order');
            }])
            ->orderBy('order')
            ->get();

        $allLessonIds = $modules->pluck('lessons')->flatten()->pluck('id');
        $totalLessons = $allLessonIds->count();

        $progressRecords = LessonProgress::where('student_id', $student->id)
            ->whereIn('lesson_id', $allLessonIds)
            ->get()
            ->keyBy('lesson_id');

        $completedCount = $progressRecords->where('status', LessonProgress::COMPLETED)->count();
        $inProgressCount = $progressRecords->where('status', LessonProgress::IN_PROGRESS)->count();

        $overallPercentage = $totalLessons > 0 ? round(($completedCount / $totalLessons) * 100, 1) : 0;

        $moduleProgress = $modules->map(function ($mod) use ($progressRecords) {
            $modLessons = $mod->lessons;
            $modTotal = $modLessons->count();
            $modCompleted = 0;

            $lessonsWithProgress = $modLessons->map(function ($lesson) use ($progressRecords, &$modCompleted) {
                $prog = $progressRecords->get($lesson->id);
                if ($prog && $prog->status === LessonProgress::COMPLETED) {
                    $modCompleted++;
                }

                return [
                    'id'                    => $lesson->id,
                    'title'                 => $lesson->title,
                    'order'                 => $lesson->order,
                    'duration_minutes'      => $lesson->duration_minutes,
                    'progress_status'       => $prog ? $prog->status : LessonProgress::NOT_STARTED,
                    'completion_percentage' => $prog ? $prog->completion_percentage : 0,
                    'time_spent_seconds'    => $prog ? $prog->time_spent_seconds : 0,
                    'completed_at'          => $prog ? $prog->completed_at : null,
                ];
            });

            return [
                'id'                 => $mod->id,
                'title'              => $mod->title,
                'order'              => $mod->order,
                'total_lessons'      => $modTotal,
                'completed_lessons'  => $modCompleted,
                'percentage'         => $modTotal > 0 ? round(($modCompleted / $modTotal) * 100, 1) : 0,
                'lessons'            => $lessonsWithProgress,
            ];
        });

        return [
            'subject_id'         => $subject->id,
            'subject_name'       => $subject->name,
            'total_lessons'      => $totalLessons,
            'completed_lessons'  => $completedCount,
            'in_progress_lessons'=> $inProgressCount,
            'overall_percentage' => $overallPercentage,
            'modules'            => $moduleProgress,
        ];
    }

    /**
     * Get aggregate progress of all students in a subject (for Admin / Tutor).
     */
    public function getSubjectOverviewForStaff(Subject $subject): array
    {
        $publishedLessonIds = Lesson::whereHas('module', function ($q) use ($subject) {
            $q->where('subject_id', $subject->id)->where('status', Module::PUBLISHED);
        })->where('status', Lesson::PUBLISHED)->pluck('id');

        $totalLessons = $publishedLessonIds->count();

        $stats = LessonProgress::whereIn('lesson_id', $publishedLessonIds)
            ->select(
                DB::raw('count(distinct student_id) as total_active_students'),
                DB::raw('sum(case when status = "completed" then 1 else 0 end) as total_completions'),
                DB::raw('sum(time_spent_seconds) as total_time_spent_seconds')
            )
            ->first();

        return [
            'subject_id'            => $subject->id,
            'subject_name'          => $subject->name,
            'total_lessons'         => $totalLessons,
            'active_students_count' => (int) ($stats->total_active_students ?? 0),
            'total_completions'     => (int) ($stats->total_completions ?? 0),
            'total_time_spent_mins' => round(($stats->total_time_spent_seconds ?? 0) / 60, 1),
        ];
    }
}
