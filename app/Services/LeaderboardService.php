<?php

namespace App\Services;

use App\Models\User;

class LeaderboardService
{
    /**
     * Return all leaderboard data.
     */
    public function getLeaderboard(User $user): array
    {
        $user->loadMissing('statistics');

        /*
        |--------------------------------------------------------------------------
        | Ensure statistics record exists
        |--------------------------------------------------------------------------
        */

        if (!$user->statistics) {

            $user->statistics()->create();

            $user->load('statistics');
        }

        /*
        |--------------------------------------------------------------------------
        | National Leaderboard
        |--------------------------------------------------------------------------
        */

        $national = User::with('statistics')
            ->whereHas('statistics')
            ->join('user_statistics', 'users.id', '=', 'user_statistics.user_id')
            ->orderByDesc('user_statistics.xp')
            ->select('users.*')
            ->take(20)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | School Leaderboard
        |--------------------------------------------------------------------------
        */

        $school = User::with('statistics')
            ->where('school', $user->school)
            ->whereHas('statistics')
            ->join('user_statistics', 'users.id', '=', 'user_statistics.user_id')
            ->orderByDesc('user_statistics.xp')
            ->select('users.*')
            ->take(20)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | District Leaderboard
        |--------------------------------------------------------------------------
        */

        $district = User::with('statistics')
            ->where('district', $user->district)
            ->whereHas('statistics')
            ->join('user_statistics', 'users.id', '=', 'user_statistics.user_id')
            ->orderByDesc('user_statistics.xp')
            ->select('users.*')
            ->take(20)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Current User Rank
        |--------------------------------------------------------------------------
        */

        $rank = User::join(
                'user_statistics',
                'users.id',
                '=',
                'user_statistics.user_id'
            )
            ->where('user_statistics.xp', '>', $user->statistics->xp)
            ->count() + 1;

        /*
        |--------------------------------------------------------------------------
        | Top Three
        |--------------------------------------------------------------------------
        */

        $topThree = $national->take(3)->values();

        return [

            'success' => true,

            'my_rank' => [

                'rank' => $rank,

                'name' => $user->name,

                'school' => $user->school,

                'district' => $user->district,

                'level' => $user->statistics->level,

                'xp' => $user->statistics->xp,

                'completed_quizzes' => $user->statistics->completed_quizzes,

                'average_percentage' => $user->statistics->average_percentage,

                'current_streak' => $user->statistics->current_streak,

            ],

            'top_three' => $this->formatLeaderboard($topThree),

            'national' => $this->formatLeaderboard($national),

            'school' => $this->formatLeaderboard($school),

            'district' => $this->formatLeaderboard($district),

            'subjects' => [],
        ];
    }

    /**
     * Format leaderboard collection.
     */
    private function formatLeaderboard($students): array
    {
        return $students->values()->map(function ($student, $index) {

            return [

                'rank' => $index + 1,

                'name' => $student->name,

                'school' => $student->school,

                'district' => $student->district,

                'level' => $student->statistics->level,

                'xp' => $student->statistics->xp,

                'average_percentage' => $student->statistics->average_percentage,

                'completed_quizzes' => $student->statistics->completed_quizzes,

                'current_streak' => $student->statistics->current_streak,

            ];

        })->toArray();
    }
}
