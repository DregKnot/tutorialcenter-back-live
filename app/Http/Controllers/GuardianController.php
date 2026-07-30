<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Guardian;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\RateLimiter;
use App\Notifications\GuardianEmailVerificationNotification;
use App\Services\BulkSMSService;

class GuardianController extends Controller
{
    /**
     * Summary of store
     */
    public function store(Request $request)
    {
        // 1. Validate input
        $validator = Validator::make($request->all(), [
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
                'confirmed',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 2. Create guardian (NOT committed yet)
            $guardian = Guardian::create([
                'email' => $request->email,
                'tel' => $request->tel,
                'password' => Hash::make($request->password),
            ]);

            // 3. Verification logic (must succeed)
            if ($guardian->email) {
                // $this->sendEmailVerification($guardian); // must throw on failure
                app(EmailVerificationService::class)->send($guardian);
            }

            if ($guardian->tel) {
                $this->sendPhoneOtp($guardian->tel); // must throw on failure
            }

            // 4. All good → commit
            DB::commit();

            return response()->json([
                'message' => 'Registration successful. Verification required.',
                'guardian' => $guardian,
            ], 201);
        } catch (\Throwable $e) {
            // 5. Something failed → rollback
            DB::rollBack();

            return response()->json([
                'message' => 'Registration failed. Verification could not be sent.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Summary of sendEmailVerification
     */
    protected function sendEmailVerification(Model $user): void
    {
        // Remove existing verification records
        EmailVerification::where('verifiable_type', get_class($user))->where('verifiable_id', $user->id)->delete();

        $token = Str::uuid();

        EmailVerification::create([
            'verifiable_type' => get_class($user),
            'verifiable_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(30),
        ]);

        $user->notify(new GuardianEmailVerificationNotification($token));
    }

    /**
     * Summary of verifyEmail
     */
    public function verifyEmail(Request $request)
    {
        try {
            $request->validate([
                'token' => 'required|string',
            ]);

            $record = EmailVerification::where('token', $request->token)->where('expires_at', '>', now())->first();

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
     */
    public function resendEmailVerification(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:students,email',
        ]);

        $guardian = Guardian::where('email', $request->email)->first();

        if ($guardian->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified.',
            ], 400);
        }

        DB::beginTransaction();

        try {
            EmailVerification::where('verifiable_type', Guardian::class)->where('verifiable_id', $guardian->id)->delete();

            app(EmailVerificationService::class)->send($guardian);

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

            $guardian = Guardian::where('tel', $request->tel)->first();

            if ($guardian->tel_verified_at) {
                return response()->json([
                    'message' => 'Phone number already verified.',
                ], 400);
            }

            $otpRecord = DB::table('phone_otps')->where('tel', $guardian->tel)->latest()->first();

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

            if (!Hash::check($request->otp, $otpRecord->code)) {
                return response()->json([
                    'message' => 'Invalid OTP.',
                ], 400);
            }

            DB::transaction(function () use ($guardian, $otpRecord) {
                $guardian->update([
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

        $guardian = Guardian::where('tel', $request->tel)->first();
        if (!$guardian) {
            return response()->json([
                'message' => 'Guardian not found.',
            ], 404);
        }

        if ($guardian->tel_verified_at) {
            return response()->json([
                'message' => 'Phone number already verified.',
            ], 400);
        }



        try {
            DB::beginTransaction();

            $this->sendPhoneOtp($guardian->tel);
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
     * Summary of completeBiodata
     **/
    public function biodata(Request $request)
    {
        // 1. Validate input
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:50',
            'surname' => 'required|string|max:50',

            'email' => 'nullable|email|exists:students,email|required_without:tel',
            'tel' => [
                'nullable',
                'string',
                'exists:students,tel',
                'required_without:email',
                'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
            ],

            'gender' => 'required|string|in:male,female,others',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'date_of_birth' => 'required|date|before:today',
            'location' => 'required|string',
            'address' => 'nullable|string',
        ]);

        // 2. Check if validation failed
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 3. Find the student by email or tel
            if ($request->email) {
                $guardian = Guardian::where('email', $request->email)->first();
            } elseif ($request->tel) {
                $guardian = Guardian::where('tel', $request->tel)->first();
            } else {
                return response()->json([
                    'message' => 'Email or phone number is required to identify the student.',
                ], 400);
            }

            // 4. Check if student exists
            if (!$guardian) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Student not found.',
                ], 404);
            }

            // 5. Check if either email or phone is verified
            if ($guardian->email_verified_at === null && $guardian->tel_verified_at === null) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Please verify your email or phone number before completing biodata.',
                ], 400);
            }

            $data = $validator->validated();

            /**
             * 6. Enforce uniqueness on update
             */

            if (isset($data['email']) && $data['email'] !== $guardian->email) {
                if (Guardian::where('email', $data['email'])->exists()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Email already in use.',
                    ], 422);
                }
                $data['email_verified_at'] = null;
            }

            if (isset($data['tel']) && $data['tel'] !== $guardian->tel) {
                if (Guardian::where('tel', $data['tel'])->exists()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Phone number already in use.',
                    ], 422);
                }
                $data['tel_verified_at'] = null;
            }

            // 7. Handle profile picture upload if provided
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                $path = $file->store('student_profile_pictures', 'public');
                $data['profile_picture'] = $path;
            }

            // 8. Update guardian biodata
            $guardian->update([
                'firstname' => $data['firstname'] ?? $guardian->firstname,
                'surname' => $data['surname'] ?? $guardian->surname,
                'email' => $data['email'] ?? $guardian->email,
                'tel' => $data['tel'] ?? $guardian->tel,
                'gender' => $data['gender'] ?? $guardian->gender,
                'profile_picture' => $data['profile_picture'] ?? $guardian->profile_picture,
                'date_of_birth' => $data['date_of_birth'] ?? $guardian->date_of_birth,
                'location' => $data['location'] ?? $guardian->location,
                'address' => $data['address'] ?? $guardian->address,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Biodata updated successfully.',
                'guardian' => $guardian->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update biodata.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Summary of update
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
            $guardian = $request->user();

            if (!$guardian) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Guardian not found.',
                ], 404);
            }

            $data = $validator->validated();

            $fields = ['firstname', 'middlename', 'surname', 'gender', 'profile_picture', 'date_of_birth', 'location', 'address'];
            $oldValues = $guardian->only($fields);

            // 3. Handle profile picture upload if provided
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                $path = $file->store('guardian_profile_pictures', 'public');
                $data['profile_picture'] = $path;
            }

            // 4. Update guardian profile
            $guardian->update([
                'firstname' => $data['firstname'] ?? $guardian->firstname,
                'middlename' => $data['middlename'] ?? $guardian->middlename,
                'surname' => $data['surname'] ?? $guardian->surname,
                'gender' => $data['gender'] ?? $guardian->gender,
                'profile_picture' => $data['profile_picture'] ?? $guardian->profile_picture,
                'date_of_birth' => $data['date_of_birth'] ?? $guardian->date_of_birth,
                'location' => $data['location'] ?? $guardian->location,
                'address' => $data['address'] ?? $guardian->address,
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

            // GuardianNotificationService::notify($guardian, 'update profile', $changes);

            return response()->json([
                'message' => 'Profile updated successfully.',
                'guardian' => $guardian->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update profile.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

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
            $guardian = Guardian::where('email', $request->entry)->orWhere('tel', $request->entry)->first();

            if (!$guardian) {
                return response()->json([
                    'message' => 'Login Fails',
                    'errors' => 'Student not found, please register ' . $request->entry,
                ], 403);
            }

            // Check password
            if (!Hash::check($request->password, $guardian->password)) {
                RateLimiter::hit($throttleKey, 60); // lock attempt for 60 seconds
                return response()->json([
                    'message' => 'Login Fails',
                    'errors' => 'Invalid Login Credentials',
                ], 403);
            }

            // Restrict login if email not verified
            if (is_null($guardian->email_verified_at) && is_null($guardian->tel_verified_at)) {
                return response()->json([
                    'message' => 'Please verify your email address before logging in.',
                    'verification_required' => 'email',
                ], 403);
            }

            // 8. Clear rate limiter after successful login
            RateLimiter::clear($throttleKey);

            //Clearing the token
            $guardian->tokens()->delete();

            // Create Sanctum token
            $token = $guardian->createToken('student-token')->plainTextToken;

            // StudentNotificationService::notify($guardian, 'login');

            return response()->json([
                'message' => 'Login successful.',
                'token' => $token,
                'guardian' => $guardian,
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
        // StudentNotificationService::notify($request->user(), 'logout');

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Summary of registerWithBiodata
     */
    public function registerWithBiodata(Request $request)
    {
        $validator = Validator::make($request->all(), [

            /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */

            'email' => 'nullable|email|unique:guardians,email|required_without:tel',

            'tel' => [
                'nullable',
                'string',
                'unique:guardians,tel',
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

            /*
        |--------------------------------------------------------------------------
        | Biodata
        |--------------------------------------------------------------------------
        */

            'firstname' => 'required|string|max:50',
            'surname' => 'required|string|max:50',
            'gender' => 'required|string|in:male,female,others',

            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            'date_of_birth' => 'required|date|before:today',

            'location' => 'required|string',

            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Registration failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $data = $validator->validated();

            /*
        |--------------------------------------------------------------------------
        | Create Guardian
        |--------------------------------------------------------------------------
        */

            $guardian = Guardian::create([
                'email' => $data['email'] ?? null,
                'tel' => $data['tel'] ?? null,
                'password' => Hash::make($data['password']),
            ]);

            /*
        |--------------------------------------------------------------------------
        | Send Verification
        |--------------------------------------------------------------------------
        */

            if ($guardian->email) {
                app(EmailVerificationService::class)->send($guardian);
            }

            if ($guardian->tel) {
                $this->sendGuardianPhoneOtp($guardian->tel);
            }

            /*
        |--------------------------------------------------------------------------
        | Upload Profile Picture
        |--------------------------------------------------------------------------
        */

            if ($request->hasFile('profile_picture')) {

                $path = $request
                    ->file('profile_picture')
                    ->store('guardian_profile_pictures', 'public');

                $data['profile_picture'] = $path;
            }

            /*
        |--------------------------------------------------------------------------
        | Save Biodata
        |--------------------------------------------------------------------------
        */

            $guardian->update([

                'firstname' => $data['firstname'],

                'surname' => $data['surname'],

                'gender' => $data['gender'],

                'profile_picture' => $data['profile_picture'] ?? null,

                'date_of_birth' => $data['date_of_birth'],

                'location' => $data['location'],

                'address' => $data['address'] ?? null,

            ]);

            DB::commit();

            return response()->json([
                'message' => 'Registration successful. Please verify your email or phone.',
                'guardian' => $guardian->fresh(),
            ], 201);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Registration failed.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Summary of sendGuardianPhoneOtp
     */
    protected function sendGuardianPhoneOtp(string $tel): void
    {
        DB::table('phone_otps')
            ->where('tel', $tel)
            ->delete();

        $code = random_int(100000, 999999);

        app(BulkSMSService::class)->sendSMS(
            $tel,
            "Your Tutorial Center Guardian verification code is {$code}. It expires in 10 minutes."
        );

        DB::table('phone_otps')->insert([
            'tel' => $tel,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
