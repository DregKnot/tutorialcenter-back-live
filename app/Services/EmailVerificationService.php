<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Student;
use App\Models\Guardian;
use App\Models\EmailVerification;
use Illuminate\Database\Eloquent\Model;
use App\Notifications\StudentEmailVerification;
use App\Notifications\StaffEmailVerificationNotification;
use App\Notifications\GuardianEmailVerificationNotification;

class EmailVerificationService
{
    public function send(Model $user): void
    {
        EmailVerification::where('verifiable_type', get_class($user))
            ->where('verifiable_id', $user->id)
            ->delete();

        // $token = Str::uuid();
        $token = rand(100000, 999999);

        EmailVerification::create([
            'verifiable_type' => get_class($user),
            'verifiable_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(30),
        ]);

        try {
            if ($user instanceof Student) {
                $user->notify(new StudentEmailVerification($token));
                return;
            }

            if ($user instanceof Staff) {
                $user->notify(new StaffEmailVerificationNotification($token));
                return;
            }

            if ($user instanceof Guardian) {
                $user->notify(new GuardianEmailVerificationNotification($token));
                return;
            }
        } catch (\Throwable $mailEx) {
            logger()->error("EmailVerificationService mail sending failed for " . ($user->email ?? 'unknown') . ": " . $mailEx->getMessage());
            logger()->info("Fallback Verification OTP for " . ($user->email ?? 'unknown') . " is: {$token}");
            if (app()->environment('production')) {
                throw $mailEx;
            }
        }
    }
}