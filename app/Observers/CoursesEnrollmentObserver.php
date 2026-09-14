<?php

namespace App\Observers;

use App\Models\CoursesEnrollment;
use App\Services\StudentNotificationService;
use Illuminate\Support\Facades\DB;

class CoursesEnrollmentObserver
{
    public function created(CoursesEnrollment $coursesEnrollment): void
    {
        $this->evaluate($coursesEnrollment);
    }

    public function updated(CoursesEnrollment $coursesEnrollment): void
    {
        if ($coursesEnrollment->wasChanged(['end_date', 'start_date', 'status'])) {
            $this->evaluate($coursesEnrollment);
        }
    }

    public function restored(CoursesEnrollment $coursesEnrollment): void
    {
        $this->evaluate($coursesEnrollment);
    }

    private function evaluate(CoursesEnrollment $enrollment): void
    {
        $id = $enrollment->id;
        DB::afterCommit(function () use ($id) {
            try {
                // Re-read current state: renewal/cancellation within the transaction wins.
                StudentNotificationService::subscriptionReminder($id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
