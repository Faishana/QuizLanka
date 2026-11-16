<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Models\UserStatistic;
use App\Notifications\StudentOtpNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;


class StudentAuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Canonical Categories
    |--------------------------------------------------------------------------
    */

    private const CATEGORY_STUDENT = User::CATEGORY_STUDENT;
    private const CATEGORY_GOV_EXAM = User::CATEGORY_GOV_EXAM;

    private const ALLOWED_CATEGORIES = [
        self::CATEGORY_STUDENT,
        self::CATEGORY_GOV_EXAM,
    ];

    private const ALLOWED_AL_STREAMS = [
        'bio',
        'maths',
        'technology',
        'arts',
        'commerce',
    ];

    /*
    |--------------------------------------------------------------------------
    | Role Helpers
    |--------------------------------------------------------------------------
    */

    private function roleForCategory(?string $category): ?string
    {
        return match ($category) {
            self::CATEGORY_STUDENT => 'student',
            self::CATEGORY_GOV_EXAM => 'government_exam_candidate',
            default => null,
        };
    }

    private function assignCategoryRole(User $user): void
    {
        $role = $this->roleForCategory($user->user_category);

        // Remove previous student category roles.
        $user->removeRole('student');

        $user->removeRole('government_exam_candidate');

        // Assign the correct category role.
        if ($role !== null) {
            $user->assignRole($role);
        }
    }

    /**
     * Check whether an account can access the student application.
     */
    private function hasStudentAccess(User $user): bool
    {
        // Allow existing Student accounts.
        if ($user->user_category === User::CATEGORY_STUDENT) {
            return true;
        }

        // Allow Government Exam Candidate accounts.
        if ($user->user_category === User::CATEGORY_GOV_EXAM) {
            return true;
        }

        // Allow newly registered accounts awaiting onboarding.
        if (
            $user->user_category === null &&
            !$user->roles()->exists()
        ) {
            return true;
        }

        // Reject Admin and unrelated accounts.
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Register
    |--------------------------------------------------------------------------
    */

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),

                // Category will be selected during onboarding.
                'user_category' => null,
                'grade_id' => null,
                'al_stream' => null,
                'school' => null,
                'district' => null,
                'preferred_medium' => null,
                'target_exam' => null,
            ]);

            UserStatistic::create([
                'user_id' => $user->id,
            ]);

            $token = $user->createToken('student_token')
                ->plainTextToken;

            $user->load('grade');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registration successful.',
                'token' => $token,
                'user' => $this->userPayload($user),
                'roles' => $user->getRoleNames(),
                'needs_onboarding' => true,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Registration failed.',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : 'Something went wrong.',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    */

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = strtolower(trim($validated['email']));

        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Check Student Application Access
        |--------------------------------------------------------------------------
        */

        if (!$this->hasStudentAccess($user)) {
            return response()->json([
                'success' => false,
                'message' => 'This account cannot access the student application.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Sanctum Token
        |--------------------------------------------------------------------------
        */

        $token = $user->createToken('student_token')->plainTextToken;

        /*
        |--------------------------------------------------------------------------
        | Check Onboarding Status
        |--------------------------------------------------------------------------
        */

        $needsOnboarding = $user->user_category === null;

        $user->load('grade');

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $this->userPayload($user),
            'roles' => $user->getRoleNames(),
            'needs_onboarding' => $needsOnboarding,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Google Login
    |--------------------------------------------------------------------------
    */

    public function googleLogin(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
        ]);

        $email = strtolower(trim($validated['email']));

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $validated['name'],
                'password' => bcrypt(Str::random(40)),
                'email_verified_at' => now(),
                'user_category' => null,
            ]
        );

        if (!$this->hasStudentAccess($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found.',
            ], 403);
        }

        // Assign category role only after onboarding.
        if ($user->user_category !== null) {
            $this->assignCategoryRole($user);
        }

        if ($user->wasRecentlyCreated) {
            UserStatistic::firstOrCreate([
                'user_id' => $user->id,
            ]);
        }

        $token = $user->createToken('student_token')
            ->plainTextToken;

        $needsOnboarding = $user->user_category === null;

        return response()->json([
            'success' => true,
            'message' => 'Google login successful.',
            'token' => $token,
            'user' => $this->userPayload($user->load('grade')),
            'roles' => $user->getRoleNames(),
            'needs_onboarding' => $needsOnboarding,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Onboarding
    |--------------------------------------------------------------------------
    */

    public function completeOnboarding(Request $request)
    {
        $user = $request->user();

        if (!$this->hasStudentAccess($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to complete onboarding.',
            ], 403);
        }

        if ($user->user_category !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Onboarding has already been completed.',
            ], 409);
        }

        // Normalize category before validation.
        $request->merge([
            'user_category' => $this->normalizeCategory(
                $request->input('user_category')
            ),
        ]);

        $category = $request->input('user_category');

        $isStudent = $category === self::CATEGORY_STUDENT;
        $isGovExam = $category === self::CATEGORY_GOV_EXAM;

        $validated = $request->validate([
            'user_category' => [
                'required',
                'string',
                Rule::in(self::ALLOWED_CATEGORIES),
            ],

            'grade_id' => $isStudent
                ? ['required', 'exists:grades,id']
                : ['prohibited'],

            'school' => $isStudent
                ? ['nullable', 'string', 'max:255']
                : ['prohibited'],

            'district' => [
                'nullable',
                'string',
                'max:255',
            ],

            'preferred_medium' => [
                'nullable',
                'string',
                'max:255',
            ],

            'target_exam' => ['nullable', 'string', 'max:255'],
        ]);

        $grade = $isStudent
            ? Grade::findOrFail($validated['grade_id'])
            : null;

        $isAlGrade = $grade
            && strtolower($grade->category ?? '') === 'al';

        $streamValidated = $request->validate([
            'al_stream' => $isAlGrade
                ? [
                    'required',
                    'string',
                    Rule::in(self::ALLOWED_AL_STREAMS),
                ]
                : ['prohibited'],
        ]);

        DB::beginTransaction();

        try {
            $user->user_category = $validated['user_category'];

            $user->grade_id = $isStudent
                ? $validated['grade_id']
                : null;

            $user->school = $isStudent
                ? ($validated['school'] ?? null)
                : null;

            $user->al_stream = $isAlGrade
                ? $streamValidated['al_stream']
                : null;

            $user->district = $validated['district'] ?? null;

            $user->preferred_medium =
                $validated['preferred_medium'] ?? null;

            $user->target_exam = $isGovExam
                ? ($validated['target_exam'] ?? null)
                : null;

            $user->save();

            // Assign the category-specific Spatie role.
            $this->assignCategoryRole($user);

            $user->load('grade');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Onboarding completed successfully.',
                'needs_onboarding' => false,
                'user' => $this->userPayload($user),
                'roles' => $user->getRoleNames(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete onboarding.',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : 'Something went wrong.',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Forgot Password - Send OTP
    |--------------------------------------------------------------------------
    */

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->email));

        $user = User::where('email', $email)->first();

        if (!$user || !$this->hasStudentAccess($user)) {
            return response()->json([
                'success' => true,
                'message' => 'If an account exists for this email, a password reset OTP has been sent.',
            ]);
        }

        PasswordResetOtp::where('email', $email)->delete();

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'email' => $email,
            'otp' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
            'verified_at' => null,
        ]);

        try {
            $user->notify(new StudentOtpNotification($otp));

        } catch (\Throwable $e) {
            PasswordResetOtp::where('email', $email)->delete();

            return response()->json([
                'success' => false,
                'message' => 'Unable to send password reset OTP. Please try again later.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account exists for this email, a password reset OTP has been sent.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Verify OTP
    |--------------------------------------------------------------------------
    */

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|digits:6',
        ]);

        $email = strtolower(trim($request->email));

        $otpRecord = PasswordResetOtp::where('email', $email)
            ->latest('created_at')
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        if (now()->greaterThan($otpRecord->expires_at)) {
            $otpRecord->delete();

            return response()->json([
                'success' => false,
                'message' => 'This OTP has expired. Please request a new OTP.',
            ], 422);
        }

        if ($otpRecord->verified_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This OTP has already been used.',
            ], 422);
        }

        if (!Hash::check($request->otp, $otpRecord->otp)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
            ], 422);
        }

        $otpRecord->update([
            'verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully. You can now reset your password.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Reset Password
    |--------------------------------------------------------------------------
    */

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $email = strtolower(trim($validated['email']));

        $otpRecord = PasswordResetOtp::where('email', $email)
            ->whereNotNull('verified_at')
            ->latest('verified_at')
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify the password reset OTP first.',
            ], 422);
        }

        if (now()->greaterThan($otpRecord->expires_at)) {
            $otpRecord->delete();

            return response()->json([
                'success' => false,
                'message' => 'The password reset session has expired. Please request a new OTP.',
            ], 422);
        }

        $user = User::where('email', $email)->first();

        if (!$user || !$this->hasStudentAccess($user)) {
            $otpRecord->delete();

            return response()->json([
                'success' => false,
                'message' => 'Unable to reset password.',
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $user->tokens()->delete();

        $otpRecord->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please login with your new password.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Me
    |--------------------------------------------------------------------------
    */

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => $this->userPayload(
                $user->load('grade')
            ),
            'needs_onboarding' => $user->user_category === null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Update Profile
    |--------------------------------------------------------------------------
    */

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $isStudent = $user->user_category === self::CATEGORY_STUDENT;

        $isGovExam = $user->user_category === self::CATEGORY_GOV_EXAM;

        $rules = [
            'name' => 'sometimes|string|max:255',
            'district' => 'sometimes|nullable|string|max:255',
            'preferred_medium' => 'sometimes|nullable|string|max:255',
        ];

        if ($isStudent) {
            $rules['grade_id'] = 'sometimes|required|exists:grades,id';
            $rules['school'] = 'sometimes|nullable|string|max:255';
        }

        if ($isGovExam) {
            $rules['target_exam'] = 'sometimes|required|string|max:255';
        }

        $validated = $request->validate($rules);

        $newGradeId = $validated['grade_id'] ?? $user->grade_id;

        $grade = $isStudent && $newGradeId
            ? Grade::find($newGradeId)
            : null;

        $isAlGrade = $grade
            && strtolower($grade->category ?? '') === 'al';

        $streamRules = [];

        if ($isStudent && $isAlGrade) {
            $streamRules['al_stream'] = [
                'required',
                'string',
                Rule::in(self::ALLOWED_AL_STREAMS),
            ];
        } else {
            $streamRules['al_stream'] = ['prohibited'];
        }

        $streamValidated = $request->validate($streamRules);

        $updateFields = [];

        if (array_key_exists('name', $validated)) {
            $updateFields['name'] = $validated['name'];
        }

        if (array_key_exists('district', $validated)) {
            $updateFields['district'] = $validated['district'];
        }

        if (array_key_exists('preferred_medium', $validated)) {
            $updateFields['preferred_medium'] = $validated['preferred_medium'];
        }

        if ($isStudent) {
            if (array_key_exists('grade_id', $validated)) {
                $updateFields['grade_id'] = $validated['grade_id'];
            }

            if (array_key_exists('school', $validated)) {
                $updateFields['school'] = $validated['school'];
            }

            if (array_key_exists('grade_id', $validated)) {
                $updateFields['al_stream'] = $isAlGrade
                    ? $streamValidated['al_stream']
                    : null;
            } elseif ($isAlGrade && $request->has('al_stream')) {
                $updateFields['al_stream'] = $streamValidated['al_stream'];
            }
        }

        if ($isGovExam && array_key_exists('target_exam', $validated)) {
            $updateFields['target_exam'] = $validated['target_exam'];
        }

        $user->update($updateFields);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user' => $this->userPayload(
                $user->fresh()->load('grade')
            ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize category input.
     */
    private function normalizeCategory(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        return match (strtolower(trim($input))) {
            'student' => self::CATEGORY_STUDENT,

            'government exam candidate',
            'government exam candidates',
            'gov exam candidate',
            'gov exam' => self::CATEGORY_GOV_EXAM,

            default => trim($input),
        };
    }

    /**
     * Consistent user response payload.
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_category' => $user->user_category,
            'role' => $user->getRoleNames()->first(),
            'grade_id' => $user->grade_id,
            'al_stream' => $user->al_stream,
            'school' => $user->school,
            'district' => $user->district,
            'preferred_medium' => $user->preferred_medium,
            'target_exam' => $user->target_exam,
            'profile_image' => $user->profile_image,

            'grade' => $user->grade ? [
                'id' => $user->grade->id,
                'name' => $user->grade->name,
            ] : null,
        ];
    }
}
