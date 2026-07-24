<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Student;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Services\BulkSMSService;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\StudentNotificationService;
use Illuminate\Support\Facades\Notification;
use App\Notifications\StudentEmailVerification;
use App\Notifications\ContactChangeOtpNotification;


class StudentController extends Controller
{
    /**
     * Login
     **/
    public function login(Request $request)
    {
        // 1️⃣ Validate input
        $validator = Validator::make($request->all(), [
            'entry' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Login Fails',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            // Create unique throttle key
            $throttleKey = Str::lower($request->input('entry')) . '|' . $request->ip();

            // Check if user is rate limited
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {

                $seconds = RateLimiter::availableIn($throttleKey);

                return response()->json([
                    'message' => "Too many login attempts. Please try again in {$seconds} seconds."
                ], 429);
            }
            //  Fetch student
            $student = Student::where('email', $request->entry)->orWhere('tel', $request->entry)->first();

            if (!$student) {
                return response()->json([
                    'message' => 'Login Fails',
                    'errors' => 'Student not found, please register ' . $request->entry,
                ], 403);
            }

            // Check password
            if (!Hash::check($request->password, $student->password)) {
                RateLimiter::hit($throttleKey, 60); // lock attempt for 60 seconds
                return response()->json([
                    'message' => 'Login Fails',
                    'errors' => 'Invalid Login Credentials',
                ], 403);
            }

            // Restrict login if email not verified
            if (is_null($student->email_verified_at) && is_null($student->tel_verified_at)) {
                return response()->json([
                    'message' => 'Please verify your email address before logging in.',
                    'verification_required' => 'email',
                ], 403);
            }

            // 8. Clear rate limiter after successful login
            RateLimiter::clear($throttleKey);

            //Clearing the token
            $student->tokens()->delete();

            // Create Sanctum token
            $token = $student->createToken('student-token')->plainTextToken;

            StudentNotificationService::notify($student, 'login');

            return response()->json([
                'message' => 'Login successful.',
                'token' => $token,
                'student' => $student,
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'message' => 'Login Fails',
                'errors' => $error->getMessage(),
            ], 500);
        }
    }

    /**
     * logout.
     **/
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        StudentNotificationService::notify($request->user(), 'logout');

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Forget Password - Send OTP to email or phone
     **/
    public function forgetPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'nullable|email|exists:students,email|required_without:tel',
                'tel' => [
                    'nullable',
                    'string',
                    'exists:students,tel',
                    'required_without:email',
                    'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
                ],
            ]);

            // Find student by email or phone
            $student = null;
            if ($request->email) {
                $student = Student::where('email', $request->email)->first();
            } elseif ($request->tel) {
                $student = Student::where('tel', $request->tel)->first();
            }

            if (!$student) {
                return response()->json([
                    'message' => 'Student not found.',
                ], 404);
            }

            DB::beginTransaction();
            // try {

            // Send OTP based on available contact method
            if ($student->email) {
                $this->sendPasswordResetEmail($student);
            }

            if ($student->tel) {
                $this->sendPasswordResetOtp($student->tel);
            }

            DB::commit();
            StudentNotificationService::notify($request->user(), 'forget password');

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
    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'nullable|email|exists:students,email|required_without:tel',
                'tel' => [
                    'nullable',
                    'string',
                    'exists:students,tel',
                    'required_without:email',
                    'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
                ],
                'otp' => 'required|string',
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'same:confirmPassword',
                ],
                'confirmPassword' => [
                    'required',
                    'string',
                    'min:8',
                    'same:password',
                ],
            ]);

            // Find student by email or phone
            $student = null;
            if ($request->email) {
                $student = Student::where('email', $request->email)->first();
            } elseif ($request->tel) {
                $student = Student::where('tel', $request->tel)->first();
            }

            if (!$student) {
                return response()->json([
                    'message' => 'Student not found.',
                ], 404);
            }

            // try {
            DB::beginTransaction();

            // Verify OTP based on contact method
            if ($request->email) {
                $record = EmailVerification::where('verifiable_type', Student::class)
                    ->where('verifiable_id', $student->id)
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
                $otpRecord = DB::table('phone_otps')->where('tel', $student->tel)->latest()->first();

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
            $student->update([
                'password' => Hash::make($request->password),
            ]);

            DB::commit();

            StudentNotificationService::notify($request->user(), 'change password');

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
     * Update student profile information
     * Allows authenticated students to update their profile (excluding email, tel and department)
     **/
    public function update(Request $request)
    {
        DB::beginTransaction();

        try {
            // 1. Validate input
            $validator = Validator::make($request->all(), [
                'firstname' => 'nullable|string|max:50',
                'middlename' => 'nullable|string|max:50',
                'surname' => 'nullable|string|max:50',
                'gender' => 'nullable|string|in:male,female,others',
                'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'date_of_birth' => 'nullable|date|before:today',
                'location' => 'nullable|string',
                'address' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return response()->json([
                    'errors' => $validator->errors(),
                    'message' => 'Validation failed.',
                ], 422);
            }

            // 2. Get authenticated student
            $student = $request->user();

            if (!$student) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Student not found.',
                ], 404);
            }

            $data = $validator->validated();

            $fields = ['firstname', 'middlename', 'surname', 'gender', 'profile_picture', 'date_of_birth', 'location', 'address'];
            $oldValues = $student->only($fields);

            // 3. Handle profile picture upload if provided
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                $path = $file->store('student_profile_pictures', 'public');
                $data['profile_picture'] = $path;
            }

            // 4. Update student profile
            $student->update([
                'firstname' => $data['firstname'] ?? $student->firstname,
                'middlename' => $data['middlename'] ?? $student->middlename,
                'surname' => $data['surname'] ?? $student->surname,
                'gender' => $data['gender'] ?? $student->gender,
                'profile_picture' => $data['profile_picture'] ?? $student->profile_picture,
                'date_of_birth' => $data['date_of_birth'] ?? $student->date_of_birth,
                'location' => $data['location'] ?? $student->location,
                'address' => $data['address'] ?? $student->address,
            ]);

            // Collect changes
            $changes = [];
            foreach ($fields as $field) {
                $newValue = $data[$field] ?? $oldValues[$field];
                if ($newValue !== $oldValues[$field]) {
                    $changes[$field] = ['from' => $oldValues[$field], 'to' => $newValue];
                }
            }

            DB::commit();

            StudentNotificationService::notify($student, 'update profile', $changes);

            return response()->json([
                'message' => 'Profile updated successfully.',
                'student' => $student->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update profile.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // Version 1: Basic registration with separate biodata completion
    public function registerWithBiodata(Request $request)
    {
        // 1. Validate everything
        $validator = Validator::make($request->all(), [
            // Auth fields
            'email' => 'nullable|email|unique:students,email|required_without:tel',
            'tel' => [
                'nullable',
                'string',
                'unique:students,tel',
                'required_without:email',
                'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'same:confirmPassword',
            ],
            'confirmPassword' => 'required|string|min:8|same:password',

            // Biodata fields
            'firstname' => 'required|string|max:50',
            'surname' => 'required|string|max:50',
            'gender' => 'required|string|in:male,female,others',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'date_of_birth' => 'required|date|before:today',
            'location' => 'required|string',
            'address' => 'nullable|string',
            'department' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
                'message' => 'Registration failed.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $data = $validator->validated();

            /**
             * 2. Create student
             */
            $student = Student::create([
                'email' => $data['email'] ?? null,
                'tel' => $data['tel'] ?? null,
                'password' => Hash::make($data['password']),
            ]);

            /**
             * 3. Trigger verification (MUST succeed)
             */
            if ($student->email) {
                app(EmailVerificationService::class)->send($student);
            }

            if ($student->tel) {
                $this->sendPhoneOtp($student->tel);
            }

            /**
             * ⚠️ IMPORTANT DESIGN DECISION
             * 
             * You CANNOT enforce verification here yet,
             * because verification happens AFTER this request.
             * 
             * So we store biodata but mark user as "pending verification"
             */

            /**
             * 4. Handle profile picture
             */
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                $path = $file->store('student_profile_pictures', 'public');
                $data['profile_picture'] = $path;
            }

            /**
             * 5. Save biodata
             */
            $student->update([
                'firstname' => $data['firstname'],
                'surname' => $data['surname'],
                'gender' => $data['gender'],
                'profile_picture' => $data['profile_picture'] ?? null,
                'date_of_birth' => $data['date_of_birth'],
                'location' => $data['location'],
                'address' => $data['address'] ?? null,
                'department' => $data['department'],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Registration successful. Please verify your email or phone.',
                'student' => $student->fresh(),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Registration failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * Request contact change (email or phone)
     * Requires authentication - uses current user to find the request
     * Validates input, checks for existing pending requests, generates OTP, sends notification, and stores request in DB
     *
     */
    public function requestContactChange(Request $request)
    {
        try {
            $student = auth('sanctum')->user();

            if (!$student) {
                return response()->json([
                    'message' => 'Unauthorized. Please login first.',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | 1. Validate Input
            |--------------------------------------------------------------------------
            */

            $validator = Validator::make($request->all(), [
                'type' => 'required|in:phone,email',
                'value' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $type = $request->type;
            $value = $request->value;

            /*
            |--------------------------------------------------------------------------
            | 2. Type-Specific Validation
            |--------------------------------------------------------------------------
            */

            if ($type === 'phone') {
                Validator::make($request->all(), [
                    'value' => [
                        'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
                        'unique:students,tel',
                        'different:tel',
                    ],
                ])->validate();
            }

            if ($type === 'email') {
                Validator::make($request->all(), [
                    'value' => [
                        'email',
                        'unique:students,email',
                        'different:email',
                    ],
                ])->validate();
            }

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | 3. Prevent OTP Spam
            |--------------------------------------------------------------------------
            */

            $existingRequest = DB::table('contact_change_requests')
                ->where('requestable_type', Student::class)
                ->where('requestable_id', $student->id)
                ->where('change_type', $type)
                ->where('is_verified', false)
                ->where('otp_expires_at', '>', now())
                ->first();

            if ($existingRequest) {
                DB::rollBack();

                return response()->json([
                    'message' => 'OTP already sent. Please wait.',
                    'expires_at' => $existingRequest->otp_expires_at,
                ], 429);
            }

            /*
            |--------------------------------------------------------------------------
            | 4. Clean Previous Requests
            |--------------------------------------------------------------------------
            */

            DB::table('contact_change_requests')
                ->where('requestable_type', Student::class)
                ->where('requestable_id', $student->id)
                ->where('change_type', $type)
                ->delete();

            /*
            |--------------------------------------------------------------------------
            | 5. Generate OTP
            |--------------------------------------------------------------------------
            */

            $code = rand(100000, 999999);

            /*
            |--------------------------------------------------------------------------
            | 6. Send OTP
            |--------------------------------------------------------------------------
            */

            if ($type === 'email') {

                // 🔥 REAL EMAIL SENDING
                Notification::route('mail', $value)
                    ->notify(new ContactChangeOtpNotification($code, 'email'));
            } else {

                // 🔌 SMS placeholder (plug Termii / Twilio later)
                logger()->info("SMS OTP to {$value}: {$code}");
            }

            /*
            |--------------------------------------------------------------------------
            | 7. Store Request
            |--------------------------------------------------------------------------
            */

            DB::table('contact_change_requests')->insert([
                'requestable_type' => Student::class,
                'requestable_id' => $student->id,
                'change_type' => $type,
                'new_value' => $value,
                'otp_code' => $code,
                'otp_expires_at' => now()->addMinutes(10),
                'is_verified' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | 8. Send Notification
            |--------------------------------------------------------------------------
            */

            StudentNotificationService::notify(
                $request->user(),
                'contact change request',
                [
                    'change type' => $type,
                    'new value' => $value,
                    'requested at' => now()->toDateTimeString(),
                ]
            );

            return response()->json([
                'message' => "OTP sent successfully to your {$type}.",
                'type' => $type,
                'value' => $value,
                'expires_in_minutes' => 10,
            ], 200);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to send OTP.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * Confirm contact change with OTP
     * Requires authentication - uses current user to find the request
     **/
    public function confirmContactChange(Request $request)
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | 1. Validate Input
            |--------------------------------------------------------------------------
            */

            $request->validate([
                'type' => 'required|in:phone,email',
                'otp' => 'required|string|size:6',
                'value' => 'required|string', // important for extra security
            ], [
                'type.required' => 'Change type is required.',
                'type.in' => 'Invalid change type.',
                'otp.required' => 'OTP is required.',
                'otp.size' => 'OTP must be 6 digits.',
                'value.required' => 'Value is required.',
            ]);

            DB::beginTransaction();

            try {
                /*
                |--------------------------------------------------------------------------
                | 2. Find Matching Request (STRICT MATCH)
                |--------------------------------------------------------------------------
                */

                $changeRequest = DB::table('contact_change_requests')
                    ->where('change_type', $request->type)
                    ->where('otp_code', $request->otp)
                    ->where('new_value', $request->value)
                    ->where('is_verified', false)
                    ->latest()
                    ->lockForUpdate() // 🔒 prevents race condition
                    ->first();

                if (!$changeRequest) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Invalid or already used OTP.',
                    ], 400);
                }

                /*
                |--------------------------------------------------------------------------
                | 3. Check Expiry
                |--------------------------------------------------------------------------
                */

                if (Carbon::parse($changeRequest->otp_expires_at)->isPast()) {

                    DB::table('contact_change_requests')
                        ->where('id', $changeRequest->id)
                        ->delete();

                    DB::rollBack();

                    return response()->json([
                        'message' => 'OTP has expired. Please request a new one.',
                    ], 400);
                }

                /*
                |--------------------------------------------------------------------------
                | 4. Get Student
                |--------------------------------------------------------------------------
                */

                $student = Student::find($changeRequest->requestable_id);

                if (!$student) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Student not found.',
                    ], 404);
                }

                /*
                |--------------------------------------------------------------------------
                | 5. Store Old Value
                |--------------------------------------------------------------------------
                */

                $oldValue = $request->type === 'phone'
                    ? $student->tel
                    : $student->email;

                /*
                |--------------------------------------------------------------------------
                | 6. Apply Update Dynamically
                |--------------------------------------------------------------------------
                */

                if ($request->type === 'phone') {
                    $student->update([
                        'tel' => $changeRequest->new_value,
                        'tel_verified_at' => now(),
                    ]);
                }

                if ($request->type === 'email') {
                    $student->update([
                        'email' => $changeRequest->new_value,
                        'email_verified_at' => now(),
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | 7. Mark Request as Verified
                |--------------------------------------------------------------------------
                */

                DB::table('contact_change_requests')
                    ->where('id', $changeRequest->id)
                    ->update([
                        'is_verified' => true,
                        'updated_at' => now(),
                    ]);

                /*
                |--------------------------------------------------------------------------
                | 8. Cleanup Old OTP Records
                |--------------------------------------------------------------------------
                */

                if ($request->type === 'phone') {
                    DB::table('phone_otps')
                        ->where('tel', $oldValue)
                        ->delete();
                }

                if ($request->type === 'email') {
                    DB::table('email_otps')
                        ->where('email', $oldValue)
                        ->delete();
                }

                DB::commit();
                StudentNotificationService::notify(
                    $request->user(),
                    'confirm contact change',
                    [
                        'change type' => $$request->type,
                        'old value' => $oldValue,
                        'new value' => $changeRequest->new_value,
                        'changed at' => now()->toDateTimeString(),
                    ]
                );

                return response()->json([
                    'message' => ucfirst($request->type) . ' updated successfully.',
                    'type' => $request->type,
                    'new_value' => $changeRequest->new_value,
                    'verified_at' => now(),
                ], 200);
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {

            return response()->json([
                'message' => 'Failed to confirm contact change.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }


























    /**
     * Summary of sendEmailVerification
     **/
    protected function sendEmailVerification(Model $user): void
    {
        // Remove existing verification records
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

        $user->notify(new StudentEmailVerification($token));
    }

    /**
     * Summary of verifyEmail
     **/
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

            $user = $record->verifiable;

            if (!$user) {
                return response()->json([
                    'message' => 'User not found for this verification token.',
                ], 404);
            }

            $user->update([
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
     * Summary of resendEmailVerification
     **/
    public function resendEmailVerification(Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'email' => 'required|email|exists:students,email',
            ]);

            $student = Student::where('email', $request->email)->first();

            if (!$student) {
                return response()->json([
                    'message' => 'Student not found.',
                ], 404);
            }

            if ($student->email_verified_at) {
                return response()->json([
                    'message' => 'Email is already verified.',
                ], 400);
            }


            EmailVerification::where('verifiable_type', Student::class)
                ->where('verifiable_id', $student->id)
                ->delete();

            app(EmailVerificationService::class)->send($student);

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
     * Summary of sendPhoneOtp
     **/
    protected function sendPhoneOtp(string $tel): void
    {
        DB::beginTransaction();

        try {
            // 1. Delete any existing OTPs for this phone
            DB::table('phone_otps')
                ->where('tel', $tel)
                ->delete();

            // 2. Generate OTP
            $code = random_int(100000, 999999);

            $message = "Your verification code is {$code}. It expires in 10 minutes.";

            /**
             * 3. Send SMS (SIMULATED)
             * Replace this block when integrating real SMS provider
             */
            $smsSent = true; // simulate success

            // Example real usage later:
            // $smsSent = SmsService::send($tel, $message);

            if (!$smsSent) {
                throw new \Exception('SMS sending failed');
            }

            // 4. Save OTP ONLY if SMS was sent
            DB::table('phone_otps')->insert([
                'tel' => $tel,
                'code' => Hash::make($code),
                'expires_at' => Carbon::now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            // TEMP: log instead of sending SMS
            logger()->info("OTP for {$tel} is {$code}");

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e; // Let controller decide response
        }
    }


    // public function sendPhoneOtp(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'tel' => ['required', 'string'],
    //         ]);

    //         $code = random_int(100000, 999999);

    //         $response = app(BulkSMSService::class)->sendSMS(
    //             $request->tel,
    //             "Your Tutorial Center verification code is {$code}. It expires in 10 minutes."
    //         );

    //         return response()->json([
    //             'success' => true,
    //             'otp' => $code, // Remove this in production
    //             'response' => $response,
    //         ]);
    //     } catch (\Exception $error) {
    //         return response()->json([
    //             'success' => false,
    //             'errors' => $error->getMessage(),
    //         ], 500);
    //     }
    // }

    /**
     * Summary of verifyPhoneOtp
     **/
    public function verifyPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:students,tel',
                'otp' => 'required|string',
            ]);

            $student = Student::where('tel', $request->tel)->first();

            if ($student->tel_verified_at) {
                return response()->json([
                    'message' => 'Phone number already verified.',
                ], 400);
            }

            $otpRecord = DB::table('phone_otps')->where('tel', $student->tel)->latest()->first();

            if (!$otpRecord) {
                return response()->json([
                    'message' => 'OTP not found.',
                ], 400);
            }

            if (Carbon::parse($otpRecord->expires_at)->isPast()) {
                return response()->json([
                    'message' => 'OTP expired.',
                ], 400);
            }

            // if ($otpRecord->expires_at->isPast()) {
            //     return response()->json([
            //         'message' => 'OTP expired.',
            //     ], 400);
            // }

            if (!Hash::check($request->otp, $otpRecord->code)) {
                return response()->json([
                    'message' => 'Invalid OTP.',
                ], 400);
            }

            DB::transaction(function () use ($student, $otpRecord) {
                $student->update([
                    'tel_verified_at' => now(),
                ]);

                DB::table('phone_otps')->where('tel', $otpRecord->tel)->delete();
            });

            return response()->json([
                'message' => 'Phone number verified successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Phone verification failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Summary of resendPhoneOtp
     **/
    public function resendPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:students,tel',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }

        $student = Student::where('tel', $request->tel)->first();
        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        if ($student->tel_verified_at) {
            return response()->json([
                'message' => 'Phone number already verified.',
            ], 400);
        }



        try {
            DB::beginTransaction();

            $this->sendPhoneOtp($student->tel);
            DB::commit();

            return response()->json([
                'message' => 'OTP sent successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to send OTP. Try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send password reset email
     **/
    protected function sendPasswordResetEmail(Student $student): void
    {
        // Remove existing password reset records for this user
        EmailVerification::where('verifiable_type', Student::class)
            ->where('verifiable_id', $student->id)
            ->delete();

        $token = rand(100000, 999999);

        EmailVerification::create([
            'verifiable_type' => Student::class,
            'verifiable_id' => $student->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Send password reset notification (you'll need to create this notification)
        $student->notify(new \App\Notifications\StudentPasswordReset($token));
    }

    /**
     * Send password reset OTP to phone
     **/
    protected function sendPasswordResetOtp(string $tel): void
    {
        // Delete any existing OTPs for this phone
        DB::table('phone_otps')
            ->where('tel', $tel)
            ->delete();

        $code = random_int(100000, 999999);
        $message = "Your password reset code is {$code}. It expires in 10 minutes.";

        // Simulate SMS sending (replace with actual SMS service)
        $smsSent = true; // simulate success

        if (!$smsSent) {
            throw new \Exception('SMS sending failed');
        }

        DB::table('phone_otps')->insert([
            'tel' => $tel,
            'code' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        logger()->info("Password reset OTP for {$tel} is {$code}");
    }

    /**
     * Resend OTP for phone number change
     * Requires authentication
     **/
    public function resendPhoneChangeOtp(Request $request)
    {
        try {
            $student = auth('sanctum')->user();

            if (!$student) {
                return response()->json([
                    'message' => 'Unauthorized. Please login first.',
                ], 401);
            }

            DB::beginTransaction();

            try {
                // 1. Check if there's a pending phone change request
                $changeRequest = DB::table('contact_change_requests')
                    ->where('requestable_type', Student::class)
                    ->where('requestable_id', $student->id)
                    ->where('change_type', 'phone')
                    ->latest()
                    ->first();

                if (!$changeRequest) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'No pending phone change request found.',
                    ], 400);
                }

                // 2. Generate new OTP
                $code = random_int(100000, 999999);
                $message = "Your phone number change verification code is {$code}. It expires in 10 minutes.";

                // 3. Send SMS (SIMULATED)
                $smsSent = true; // simulate success

                if (!$smsSent) {
                    throw new \Exception('SMS sending failed');
                }

                // 4. Update the contact change request with new OTP
                DB::table('contact_change_requests')
                    ->where('id', $changeRequest->id)
                    ->update([
                        'otp_code' => $code,
                        'otp_expires_at' => Carbon::now()->addMinutes(10),
                        'updated_at' => now(),
                    ]);

                DB::commit();

                // TEMP: log instead of sending SMS
                logger()->info("Phone change OTP resent for {$changeRequest->new_value} is {$code}");

                return response()->json([
                    'message' => 'OTP resent successfully to your new phone number.',
                    'new_tel' => $changeRequest->new_value,
                    'expires_in_minutes' => 10,
                ], 200);
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to resend OTP.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * (Admin) Delete staff using soft delete
     */
    public function destroy($id)
    {
        try {
            $student = Student::findOrFail($id);

            $student->delete();

            return response()->json([
                'message' => 'Student suspended successfully.',
                'student' => $student,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to suspend student.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * (Admin) Restore staff using soft delete
     */
    public function restore($id)
    {
        try {
            $student = Student::withTrashed()->findOrFail($id);
            $student->restore();

            return response()->json([
                'message' => 'Student restored successfully.',
                'student' => $student,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to restore student.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * Fetch all students (for admin use)
     */
    public function index()
    {
        $students = Student::withTrashed()->get();
        return response()->json([
            'message' => 'Students retrieved successfully.',
            'students' => $students,
        ], 200);
    }

    /*
     * Fetch single student by ID (for admin use)
     */
    public function show($id)
    {
        $student = Student::withTrashed()
            ->with([
                'guardians',
                'courseEnrollments.course',
                'courseEnrollments.course.subjects',
                'advisors',
                'attendances',
            ])
            ->find($id);

        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Student retrieved successfully.',
            'student' => [
                'information' => $student,
                'guardians' => $student->guardians,
                'courses' => $student->courseEnrollments->map(function ($enrollment) {
                    return [
                        'enrollment_id' => $enrollment->id,
                        'student_id' => $enrollment->student_id,
                        'course_id' => $enrollment->course_id,
                        'enrollment_status' => $enrollment->status ?? null,
                        'enrolled_at' => $enrollment->created_at,
                        'subjects' => $enrollment->course->subjects ?? [],
                        'course_information' => $enrollment->course,
                    ];
                }),
                'enrolled_subjects' => $student->courseEnrollments->flatMap(function ($enrollment) {
                    return $enrollment->course->subjects ?? [];
                })->unique('id')->values(),
                'advisors' => $student->advisors,
                'attendance' => $student->attendances,
            ],
        ], 200);
    }
}
