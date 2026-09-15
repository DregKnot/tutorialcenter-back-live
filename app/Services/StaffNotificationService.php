<?php 
namespace App\Services;

use App\Models\Staff;
use App\Models\Classes;
use App\Notifications\StaffClassAssignedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\StaffActivityNotification;

class StaffNotificationService
{
    /** Direct delivery, called only after class creation commits. */
    public static function classAssigned(Classes $class, array $staffIds): void
    {
        if ($staffIds === []) {
            return;
        }

        $class->loadMissing(['subject', 'schedules']);
        $sessionDates = $class->sessions()
            ->whereDate('session_date', '>=', today())
            ->orderBy('session_date')
            ->pluck('session_date');
        $recipients = $class->staffs()->whereIn('staffs.id', array_unique($staffIds))->get();

        foreach ($recipients as $staff) {
            $notification = new StaffClassAssignedNotification([
                'class_id' => $class->id,
                'subject_id' => $class->subject_id,
                'subject' => $class->subject?->name ?? 'Class',
                'title' => $class->title,
                'status' => $class->status,
                'role' => $staff->pivot->role ?? 'lead',
                'timezone' => config('app.timezone', 'UTC'),
                'schedules' => $class->schedules->map(fn ($schedule) => [
                    'day_of_week' => $schedule->day_of_week,
                    'start_time' => substr($schedule->start_time, 0, 5),
                    'end_time' => substr($schedule->end_time, 0, 5),
                ])->values()->all(),
                'first_session_date' => $sessionDates->first()?->toDateString(),
                'last_session_date' => $sessionDates->last()?->toDateString(),
                'assigned_at' => now()->toISOString(),
            ]);

            self::deliver($class->id, $staff->id, 'database', fn () => $staff->notifyNow($notification, ['database']));

            $email = trim((string) $staff->email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                self::deliver($class->id, $staff->id, 'mail', function () use ($staff, $email, $notification) {
                    $recipient = clone $staff;
                    $recipient->email = $email;
                    $recipient->notifyNow($notification, ['mail']);
                });
            } else {
                Log::info('Class assignment channel skipped: missing or invalid contact', ['class_id' => $class->id, 'staff_id' => $staff->id, 'channel' => 'mail']);
            }

            $phone = self::smsPhone($staff->tel);
            if ($phone !== null) {
                self::deliver($class->id, $staff->id, 'sms', fn () => app(BulkSMSService::class)->sendSMS($phone, $notification->toSms()));
            } else {
                Log::info('Class assignment channel skipped: missing or invalid contact', ['class_id' => $class->id, 'staff_id' => $staff->id, 'channel' => 'sms']);
            }
        }
    }

    private static function deliver(int $classId, int $staffId, string $channel, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $exception) {
            // Never let one failed channel prevent other recipients/channels or undo creation.
            Log::warning('Class assignment notification failed', [
                'class_id' => $classId,
                'staff_id' => $staffId,
                'channel' => $channel,
                'exception' => get_class($exception),
            ]);
        }
    }

    private static function smsPhone(?string $phone): ?string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim((string) $phone));
        if (preg_match('/^0[789][0-9]{9}$/', $phone)) {
            return '+234'.substr($phone, 1);
        }
        if (preg_match('/^234[789][0-9]{9}$/', $phone)) {
            return '+'.$phone;
        }
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) ? $phone : null;
    }

    public static function notify(string $type, string $message, array $data = [])
    {
        // Get particular staff members
        $staffMembers = Staff::all();

        if ($staffMembers->isEmpty()) {
            return;
        }

        Notification::send(
            $staffMembers,
            new StaffActivityNotification($type, $message, $data)
        );
    }
}