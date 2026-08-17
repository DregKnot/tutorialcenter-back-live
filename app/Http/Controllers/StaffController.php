<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Staff;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\AdminNotificationService;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Notifications\StaffActivityNotification;

class StaffController extends Controller
{
    /**
     * Staff login.
     */
    public function login(Request $request)
    {
        try {
            // 1. Validate input
            $request->validate([
                'login' => 'required|string',
                'password' => 'required|string',
            ]);

            // 2. Create unique throttle key
            $throttleKey = Str::lower($request->input('login')) . '|' . $request->ip();

            // 3. Check if user is rate limited
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                return response()->json([
                    'message' => "Too many login attempts. Please try again in {$seconds} seconds."
                ], 429);
            }

            // 4. Find staff by email or staff_id
            $staff = Staff::where('email', $request->login)->orWhere('staff_id', $request->login)->first();

            // 5. Validate credentials
            if (!$staff || !Hash::check($request->password, $staff->password)) {

                RateLimiter::hit($throttleKey, 60); // lock attempt for 60 seconds

                throw ValidationException::withMessages([
                    'login' => ['Invalid login credentials.'],
                ]);
            }

            // 6. Email verification check
            if (!$staff->email_verified_at) {
                return response()->json([
                    'message' => 'Please verify your email before logging in.'
                ], 403);
            }

            // // 7. Phone verification check
            // if (!$staff->tel_verified_at) {
            //     return response()->json([
            //         'message' => 'Please verify your phone number before logging in.'
            //     ], 403);
            // }

            // 8. Clear rate limiter after successful login
            RateLimiter::clear($throttleKey);

            // 9. Remove all existing tokens
            $staff->tokens()->delete();

            // 10. Create new token
            $token = $staff->createToken('staff-token')->plainTextToken;

            $staff->notify(new StaffActivityNotification(
                $staff->id,
                "{$staff->role} {$staff->firstname} {$staff->surname} logged in."
            ));

            return response()->json([
                'message' => 'Login successful.',
                'token' => $token,
                'staff' => $staff,
                'role' => $staff->role,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Login failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Staff logout.
     **/
    public function logout(Request $request)
    {
        $staff = $request->user();
        $request->user()->tokens()->delete();
        $staff->notify(new StaffActivityNotification(
            $staff->id,
            "{$staff->role} {$staff->firstname} {$staff->surname} logged out."
        ));

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Forget Password - Send OTP to email or phone
     **/
    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'nullable|email|exists:staffs,email|required_without:tel',
                'tel' => [
                    'nullable',
                    'string',
                    'exists:staffs,tel',
                    'required_without:email',
                    'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
                ],
            ]);

            // Find staff by email or phone
            $staff = null;
            if ($request->email) {
                $staff = Staff::where('email', $request->email)->first();
            } elseif ($request->tel) {
                $staff = Staff::where('tel', $request->tel)->first();
            }

            if (!$staff) {
                return response()->json([
                    'message' => 'Staff not found.',
                ], 404);
            }

            DB::beginTransaction();
            // try {

            // Send OTP based on available contact method
            if ($staff->email) {
                $this->sendPasswordResetEmail($staff);
            }

            // if ($staff->tel) {
            //     $this->sendPasswordResetOtp($staff->tel);
            // }

            DB::commit();

            $staff->notify(new StaffActivityNotification(
                $staff->id,
                "{$staff->role} {$staff->firstname} {$staff->surname} requested a password reset."
            ));

            return response()->json([
                'message' => 'Password reset OTP sent successfully.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to send password reset OTP.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Change Password using OTP
     **/
    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'nullable|email|exists:staffs,email|required_without:tel',
                'tel' => [
                    'nullable',
                    'string',
                    'exists:staffs,tel',
                    'required_without:email',
                    'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
                ],
                'otp' => 'required|string',
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'same:confirmPassword',
                    // 'confirmed',
                    // 'regex:/[a-z]/',
                    // 'regex:/[A-Z]/',
                    // 'regex:/[0-9]/',
                    // 'regex:/[@$!%*#?&]/',
                ],
                'confirmPassword' => [
                    'required',
                    'string',
                    'min:8',
                    'same:password',
                    // 'confirmed',
                    // 'regex:/[a-z]/',
                    // 'regex:/[A-Z]/',
                    // 'regex:/[0-9]/',
                    // 'regex:/[@$!%*#?&]/',
                ],
            ]);

            // Find staff by email or phone
            $staff = null;
            if ($request->email) {
                $staff = Staff::where('email', $request->email)->first();
            } elseif ($request->tel) {
                $staff = Staff::where('tel', $request->tel)->first();
            }

            if (!$staff) {
                return response()->json([
                    'message' => 'Staff not found.',
                ], 404);
            }

            // try {
            DB::beginTransaction();

            // Verify OTP based on contact method
            if ($request->email) {
                $record = EmailVerification::where('verifiable_type', Staff::class)
                    ->where('verifiable_id', $staff->id)
                    ->where('token', $request->otp)
                    ->where('expires_at', '>', now())
                    ->first();

                if (!$record) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Invalid or expired OTP.',
                    ], 400);
                }

                $record->delete();
            } elseif ($request->tel) {
                $otpRecord = DB::table('phone_otps')->where('tel', $staff->tel)->latest()->first();

                if (!$otpRecord) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'OTP not found.',
                    ], 400);
                }

                if (Carbon::parse($otpRecord->expires_at)->isPast()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'OTP expired.',
                    ], 400);
                }

                if (!Hash::check($request->otp, $otpRecord->code)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Invalid OTP.',
                    ], 400);
                }

                DB::table('phone_otps')->where('tel', $otpRecord->tel)->delete();
            }

            // Update password
            $staff->update([
                'password' => Hash::make($request->password),
            ]);

            DB::commit();
            $staff->notify(new StaffActivityNotification(
                'change password',
                "{$staff->role} {$staff->firstname} {$staff->surname} changed their password."
            ));

            return response()->json([
                'message' => 'Password changed successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to change password.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Staff registration (Admin only - enforced in controller)
     */
    public function store(Request $request)
    {
        // 1. Validate input
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:50',
            'middlename' => 'nullable|string|max:50',
            'surname' => 'required|string|max:50',

            'email' => 'required|email|unique:staffs,email',
            'tel' => [
                'required',
                'string',
                'unique:staffs,tel',
                'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
            ],

            'gender' => 'required|in:male,female,others',
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'date_of_birth' => 'required|date|before:today',

            'location' => 'required|string',
            'address' => 'required|string',

            'role' => 'required|in:admin,tutor,advisor,moderator,coo,preview',

            'inducted_by' => 'nullable|exists:staffs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. Ensure inductor is admin (if provided)
        if ($request->inducted_by) {
            $inductor = Staff::where('id', $request->inducted_by)
                ->where('role', 'admin')
                ->first();

            if (!$inductor) {
                return response()->json([
                    'message' => 'Inductor must be an admin staff.',
                ], 400);
            }
        }

        DB::beginTransaction();

        try {
            // 3. Upload profile picture
            if ($request->hasFile('profile_picture')) {
                $picturePath = $request->file('profile_picture')
                    ->store('staff_profile_pictures', 'public');
            } else {
                $picturePath = 'default-avatar.png';
            }
            // $picturePath = $request->file('profile_picture')
            //     ->store('staff_profile_pictures', 'public');

            // 4. Generate staff ID
            $staffCount = Staff::withTrashed()->count() + 1;
            $staffId = 'TC' . now()->format('ym') . str_pad($staffCount, 4, '0', STR_PAD_LEFT);

            // 5. Create staff (NOT committed yet)
            $staff = Staff::create([
                'staff_id' => $staffId,
                'firstname' => $request->firstname,
                'middlename' => $request->middlename,
                'surname' => $request->surname,
                'email' => $request->email,
                'tel' => $request->tel,
                'password' => Hash::make($staffId),
                'gender' => $request->gender,
                'profile_picture' => $picturePath,
                'date_of_birth' => $request->date_of_birth,
                'location' => $request->location,
                'address' => $request->address,
                'role' => $request->role,
                'inducted_by' => $request->inducted_by,
            ]);

            // 6. Send verifications (MUST succeed)
            app(EmailVerificationService::class)->send($staff);
            $this->sendPhoneOtp($staff->tel);

            DB::commit();

            AdminNotificationService::notify(
                'Staff Registered',
                "New staff registered: {$staff->firstname} {$staff->surname} ({$staff->staff_id})",
                ['staff_id' => $staff->id]
            );

            return response()->json([
                'message' => 'Staff registered successfully. Verification required.',
                'staff' => $staff,
                'temporary_password' => $staffId, // remove later in production
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Staff registration failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

        /*
     * (Admin) Delete staff using soft delete
     */
    public function destroy($id, Request $request)
    {
        try {
            $staff = Staff::findOrFail($id);
            if ($staff->role === 'admin') {
                return response()->json([
                    'message' => 'Cannot suspend an admin staff.',
                ], 403);
            }
            $staff->delete();

            AdminNotificationService::notify(
                'Staff Suspended',
                "Staff suspended: {$staff->firstname} {$staff->surname} ({$staff->staff_id}) by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['staff_id' => $staff->id]
            );

            return response()->json([
                'message' => 'Staff suspended successfully.',
                'staff' => $staff,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to suspend staff.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * (Admin) Restore staff using soft delete
     */
    public function restore($id, Request $request)
    {
        try {
            $staff = Staff::withTrashed()->findOrFail($id);
            $staff->restore();

            AdminNotificationService::notify(
                'Staff Restored',
                "Staff restored: {$staff->firstname} {$staff->surname} ({$staff->staff_id}) by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['staff_id' => $staff->id]
            );

            return response()->json([
                'message' => 'Staff restored successfully.',
                'staff' => $staff,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to restore staff.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * (Admin) Edit staff details
     */
    public function update(Request $request, $id)
    {
        try {
            $staff = Staff::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'firstname' => 'sometimes|required|string|max:50',
                'middlename' => 'sometimes|string|max:50',
                'surname' => 'sometimes|required|string|max:50',

                'email' => 'sometimes|required|email|unique:staffs,email,' . $staff->id,
                'tel' => [
                    'sometimes',
                    'required',
                    'string',
                    'unique:staffs,tel,' . $staff->id,
                    'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
                ],
                'gender' => 'sometimes|in:male,female,others',
                'profile_picture' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
                'date_of_birth' => 'sometimes|date|before:today',

                'location' => 'sometimes|string',
                'address' => 'sometimes|string',

                'role' => 'sometimes|in:admin,tutor,advisor,moderator,coo,preview',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($request->hasFile('profile_picture')) {
                // If staff had an existing picture, remove it
                if ($staff->profile_picture) {
                    Storage::disk('public')->delete($staff->profile_picture);
                }

                $picturePath = $request->file('profile_picture')
                    ->store('staff_profile_pictures', 'public');
                $staff->profile_picture = $picturePath;
            }

            $staff->fill($request->except('profile_picture'));
            $staff->save();

            AdminNotificationService::notify(
                'Staff Updated',
                "Staff details updated for: {$staff->firstname} {$staff->surname} ({$staff->staff_id}) by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['staff_id' => $staff->id]
            );

            return response()->json([
                'message' => 'Staff details updated successfully.',
                'staff' => $staff,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update staff details.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }










































    /**
     * Get all staff (Admin only - enforced in controller)
     */
    public function index()
    {
        try {
            $staffs = Staff::withTrashed()->get();
            return response()->json([
                'message' => 'Staff retrieved successfully.',
                'staffs' => $staffs,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve staff.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Verify staff email.
     */
    public function verifyEmail(Request $request)
    {
        try {
            $request->validate([
                'token' => 'required|string',
            ]);

            $record = EmailVerification::where('token', $request->token)
                ->where('expires_at', '>', now())
                ->first();

            if (!$record) {
                return response()->json([
                    'message' => 'Invalid or expired verification link.',
                ], 400);
            }

            $staff = $record->verifiable;

            if (!$staff) {
                return response()->json([
                    'message' => 'Staff not found.',
                ], 404);
            }

            $staff->update([
                'email_verified_at' => now(),
            ]);

            $record->delete();

            return response()->json([
                'message' => 'Email verified successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Email verification failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Resend staff email verification.
     */
    public function resendEmailVerification(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:staffs,email',
            ]);

            $staff = Staff::where('email', $request->email)->first();

            if ($staff->email_verified_at) {
                return response()->json([
                    'message' => 'Email already verified.',
                ], 400);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to process request.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        DB::beginTransaction();
        try {
            app(EmailVerificationService::class)->send($staff);

            DB::commit();

            return response()->json([
                'message' => 'Verification email resent successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to resend verification email.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send phone OTP to staff.
     */
    protected function sendPhoneOtp(string $tel): void
    {
        DB::beginTransaction();

        try {
            DB::table('phone_otps')->where('tel', $tel)->delete();

            $code = random_int(100000, 999999);

            $smsSent = true; // integrate real SMS later

            if (!$smsSent) {
                throw new \Exception('SMS sending failed');
            }

            DB::table('phone_otps')->insert([
                'tel' => $tel,
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            logger()->info("Staff OTP for {$tel}: {$code}");
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Verify staff phone OTP.
     */
    public function verifyPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:staffs,tel',
                'otp' => 'required|string',
            ]);

            $staff = Staff::where('tel', $request->tel)->first();

            if ($staff->tel_verified_at) {
                return response()->json([
                    'message' => 'Phone already verified.',
                ], 400);
            }

            $otp = DB::table('phone_otps')
                ->where('tel', $staff->tel)
                ->latest()
                ->first();

            if (!$otp || Carbon::parse($otp->expires_at)->isPast()) {
                return response()->json([
                    'message' => 'Invalid or expired OTP.',
                ], 400);
            }

            if (!Hash::check($request->otp, $otp->code)) {
                return response()->json([
                    'message' => 'Invalid OTP.',
                ], 400);
            }

            DB::transaction(function () use ($staff, $otp) {
                $staff->update([
                    'tel_verified_at' => now(),
                ]);

                DB::table('phone_otps')->where('tel', $otp->tel)->delete();
            });
                
            return response()->json([
                'message' => 'Phone verified successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Phone verification failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Resend staff phone OTP.
     */
    public function resendPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:staffs,tel',
            ]);

            $staff = Staff::where('tel', $request->tel)->first();

            if ($staff->tel_verified_at) {
                return response()->json([
                    'message' => 'Phone already verified.',
                ], 400);
            }

            // try {
            DB::beginTransaction();

            $this->sendPhoneOtp($staff->tel);

            DB::commit();

            return response()->json([
                'message' => 'OTP resent successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to resend OTP.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    * (Admin) show staff profile details
    */
    public function show($id)
    {
        try {
            $staff = Staff::withTrashed()->findOrFail($id);

            return response()->json([
                'message' => 'Profile retrieved successfully.',
                'staff' => $staff,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve profile.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * (Admin) List active staffs base on the personal access token table
     */
    public function activeStaffs()
    {
        try {
            $activeStaffIds = DB::table('personal_access_tokens')
                ->where('name', 'staff-token')
                ->pluck('tokenable_id')
                ->unique();

            $activeStaffs = Staff::whereIn('id', $activeStaffIds)->get();

            return response()->json([
                'message' => 'Active staffs retrieved successfully.',
                'staffs' => $activeStaffs,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve active staffs.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send password reset email
     **/
    protected function sendPasswordResetEmail(Staff $staff): void
    {
        // Remove existing password reset records for this user
        EmailVerification::where('verifiable_type', Staff::class)
            ->where('verifiable_id', $staff->id)
            ->delete();

        $token = rand(100000, 999999);

        EmailVerification::create([
            'verifiable_type' => Staff::class,
            'verifiable_id' => $staff->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Send password reset notification (you'll need to create this notification)
        $staff->notify(new \App\Notifications\StaffPasswordReset($token));
    }
}
