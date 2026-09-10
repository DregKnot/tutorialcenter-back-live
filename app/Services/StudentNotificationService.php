<?php
namespace App\Services;

use App\Models\Student;
use App\Notifications\StudentActivityNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;

class StudentNotificationService
{
    public static function notify(?Student $student, string $type, array $data = [])
    {
        if (!$student) {
            return;
        }

        // Prevent spam (optional)
        $key = "student-activity-{$type}-{$student->id}";

        if (Cache::has($key)) {
            return;
        }

        // Load relationships
        $student->load(['guardians', 'advisors']);

        $notification = new StudentActivityNotification($student, $type, $data);

        /*
        |--------------------------------------------------------------------------
        | 1. Notify Student
        |--------------------------------------------------------------------------
        */
        $student->notify($notification);

        /*
        |--------------------------------------------------------------------------
        | 2. Notify Guardians
        |--------------------------------------------------------------------------
        */
        if ($student->guardians->isNotEmpty()) {
            Notification::send($student->guardians, $notification);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Notify Advisors
        |--------------------------------------------------------------------------
        */
        if ($student->advisors->isNotEmpty()) {
            Notification::send($student->advisors, $notification);
        }

        // Cache to prevent spam (5 mins)
        Cache::put($key, true, now()->addMinutes(5));
    }
}