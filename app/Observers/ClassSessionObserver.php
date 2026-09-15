<?php

namespace App\Observers;

use App\Models\ClassSession;
use App\Services\StudentNotificationService;
use Illuminate\Support\Facades\DB;

class ClassSessionObserver
{
    public function created(ClassSession $classSession): void
    {
        $id = $classSession->id;
        DB::afterCommit(function () use ($id) {
            try {
                StudentNotificationService::sessionsCreated($id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    public function updated(ClassSession $classSession): void
    {
        // Capture the original value before Eloquent synchronizes model attributes.
        if (! $classSession->wasChanged('recording_link')
            || filled($classSession->getRawOriginal('recording_link'))
            || blank($classSession->recording_link)) {
            return;
        }
        $id = $classSession->id;
        DB::afterCommit(function () use ($id) {
            try {
                StudentNotificationService::recordingAvailable($id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
