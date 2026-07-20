<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\UserStatistic;
use Illuminate\Support\Facades\DB;


class StudentAuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'grade_id' => 'required|exists:grades,id',
            'school' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();

        try {

            // Create Student
            $user = User::create([
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
                'grade_id'  => $request->grade_id,
                'school'    => $request->school,
                'district'  => $request->district,
            ]);

            // Create Statistics Record
            UserStatistic::create([
                'user_id' => $user->id,
            ]);

            // Assign Student Role
            $user->assignRole('student');

            // Generate Sanctum Token
            $token = $user->createToken('student_token')->plainTextToken;

            // Load Relationships
            $user->load('grade');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registration successful.',
                'token'   => $token,
                'user'    => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'school' => $user->school,
                    'district' => $user->district,
                    'grade' => [
                        'id' => $user->grade?->id,
                        'name' => $user->grade?->name,
                    ],
                ],
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Registration failed.',
                'error' => app()->environment('local') ? $e->getMessage() : 'Something went wrong.',
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user = Auth::user();

        if (!$user->hasRole('student')) {
            return response()->json([
                'success' => false,
                'message' => 'Student account not found'
            ], 403);
        }

        $token = $user->createToken('student_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user,
            'roles' => $user->getRoleNames(),
        ]);
    }

    public function googleLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'required|string',
        ]);

        $user = User::firstOrCreate(
            ['email' => $request->email],
            [
                'name' => $request->name,
                'password' => bcrypt(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );

        if (!$user->hasRole('student')) {
            $user->assignRole('student');
        }

        $token = $user->createToken('student_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load('grade')
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'grade_id' => 'sometimes|exists:grades,id',
            'school' => 'sometimes|string|max:255',
            'district' => 'sometimes|string|max:255',
        ]);

        $request->user()->update(
            $request->only([
                'name',
                'grade_id',
                'school',
                'district'
            ])
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $request->user(),
        ]);
    }
}
