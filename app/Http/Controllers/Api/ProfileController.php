<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Get Admin Profile
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,

                // Optional (if using Spatie Permission)
                'role' => $user->getRoleNames()->first() ?? 'Administrator',

                'member_since' => $user->created_at->format('F Y'),
            ]
        ]);
    }

    /**
     * Update Admin Profile
     */
    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();

        $user->update([
            'name' => $request->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,

                // Optional (if using Spatie Permission)
                'role' => $user->getRoleNames()->first() ?? 'Administrator',

                'member_since' => $user->created_at->format('F Y'),
            ]
        ]);
    }

    /**
     * Change Admin Password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {

            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);

        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }
}
