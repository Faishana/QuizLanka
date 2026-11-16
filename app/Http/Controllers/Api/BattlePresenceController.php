<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BattlePresenceController extends Controller
{
    /**
     * Mark the authenticated student as online.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->last_seen_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Online status updated.',
            'last_seen_at' => $user->last_seen_at,
        ]);
    }

    /**
     * Return online students from the same grade and medium.
     */
    public function onlineStudents(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->grade_id || !$user->preferred_medium) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Please set your grade and preferred medium first.',
            ]);
        }

        $students = User::query()
            ->where('grade_id', $user->grade_id)
            ->where('preferred_medium', $user->preferred_medium)
            ->where('id', '!=', $user->id)
            ->where('last_seen_at', '>=', now()->subSeconds(60))
            ->select([
                'id',
                'name',
                'grade_id',
                'preferred_medium',
                'profile_image',
                'last_seen_at',
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $students,
        ]);
    }
}
