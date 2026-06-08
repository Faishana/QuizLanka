<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register
     */
    public function register(Request $request)
    {
        $request->validate([

            'name' => 'required|string|max:255',

            'email' => 'required|email|unique:users,email',

            'password' => 'required|min:6|confirmed',
        ]);

        $user = User::create([

            'name' => $request->name,

            'email' => $request->email,

            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('student');

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([

            'success' => true,

            'message' => 'Registration successful',

            'token' => $token,

            'user' => $user,
        ]);
    }

    /**
     * Login
     */
    public function login(Request $request)
    {
        $request->validate([

            'email' => 'required|email',

            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {

            return response()->json([

                'success' => false,

                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([

            'success' => true,

            'message' => 'Login successful',

            'token' => $token,

            'user' => $user,
        ]);
    }

    /**
     * Current User
     */
    public function me(Request $request)
    {
        return response()->json([

            'success' => true,

            'user' => $request->user(),
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([

            'success' => true,

            'message' => 'Logged out successfully',
        ]);
    }
}
