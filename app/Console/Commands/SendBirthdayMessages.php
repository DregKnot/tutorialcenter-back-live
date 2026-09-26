<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Models\Staff;
use App\Models\Student;
use App\Notifications\BirthdayNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBirthdayMessages extends Command
{
    protected $signature = 'users:send-birthday-messages';

    protected $description = 'Send tailored birthday emails and in-app notifications to Students, Guardians, and Staff';

    public function handle(): int
    {
        $tz = config('app.timezone', 'Africa/Lagos');
        $today = now()->timezone($tz);
        $month = $today->month;
        $day = $today->day;
        $year = $today->year;

        $this->info("Checking birthdays for {$today->toDateString()} (Month: {$month}, Day: {$day})...");

        $studentCount = $this->processGroup(
            Student::whereNotNull('date_of_birth')
                ->whereMonth('date_of_birth', $month)
                ->whereDay('date_of_birth', $day),
            'student',
            $year
        );

        $guardianCount = $this->processGroup(
            Guardian::whereNotNull('date_of_birth')
                ->whereMonth('date_of_birth', $month)
                ->whereDay('date_of_birth', $day),
            'guardian',
            $year
        );

        $staffCount = $this->processGroup(
            Staff::whereNotNull('date_of_birth')
                ->whereMonth('date_of_birth', $month)
                ->whereDay('date_of_birth', $day),
            'staff',
            $year
        );

        $this->info("Birthday messages sent: {$studentCount} students, {$guardianCount} guardians, {$staffCount} staff.");

        return self::SUCCESS;
    }

    private function processGroup($query, string $role, int $year): int
    {
        $count = 0;

        $query->chunkById(100, function ($users) use ($role, $year, &$count) {
            foreach ($users as $user) {
                try {
                    $recipientType = $user->getMorphClass();
                    $recipientId = $user->getKey();
                    $deliveryKey = hash('sha256', "birthday|{$recipientType}|{$recipientId}|{$year}");

                    // Check and record deduplication so each user is only greeted once per calendar year
                    $claimed = (bool) DB::table('student_activity_deliveries')->insertOrIgnore([
                        'delivery_key' => $deliveryKey,
                        'student_id' => $role === 'student' ? $user->id : 0,
                        'event_type' => 'birthday_greeting',
                        'recipient_type' => $recipientType,
                        'recipient_id' => $recipientId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if (! $claimed) {
                        continue;
                    }

                    $notification = new BirthdayNotification($role);

                    // Send database notification immediately
                    $user->notifyNow($notification, ['database']);

                    // Send mail notification after commit / safely
                    if (in_array('mail', $notification->via($user), true)) {
                        try {
                            $user->notifyNow($notification, ['mail']);
                        } catch (\Throwable $mailException) {
                            report($mailException);
                        }
                    }

                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        return $count;
    }
}