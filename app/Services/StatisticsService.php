<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserStatistic;
use App\Models\Quiz;
use Carbon\Carbon;

class StatisticsService
{
    /**
     * Refresh all statistics for a student.
     */
    public static function refresh(User $user): UserStatistic
    {
        $statistics = UserStatistic::firstOrCreate([
            'user_id' => $user->id,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Completed Quizzes
        |--------------------------------------------------------------------------
        */

        $quizzes = Quiz::where('user_id', $user->id)
            ->where('is_completed', true)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Quiz Statistics
        |--------------------------------------------------------------------------
        */

        $completedQuizzes = $quizzes->count();

        $correctAnswers = $quizzes->sum('correct_answers');

        $wrongAnswers = $quizzes->sum('wrong_answers');

        $averageScore = round(
            $quizzes->avg('score') ?? 0,
            2
        );

        $averagePercentage = round(
            $quizzes->avg('percentage') ?? 0,
            2
        );

        $totalStudyTime = $quizzes->sum('duration_seconds');

        /*
        |--------------------------------------------------------------------------
        | XP System
        |--------------------------------------------------------------------------
        */

        $xp =
            ($correctAnswers * 10) +
            ($completedQuizzes * 20);

        /*
        |--------------------------------------------------------------------------
        | Level System
        |--------------------------------------------------------------------------
        */

        $level = max(
            1,
            floor($xp / 1000) + 1
        );

        /*
        |--------------------------------------------------------------------------
        | Streak
        |--------------------------------------------------------------------------
        */

        $lastQuiz = $quizzes
            ->sortByDesc('completed_at')
            ->first();

        $currentStreak = 0;

        if ($lastQuiz && $lastQuiz->completed_at) {

            $days = Carbon::parse(
                $lastQuiz->completed_at
            )->diffInDays(now());

            if ($days <= 1) {
                $currentStreak = 1;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save Statistics
        |--------------------------------------------------------------------------
        */

        $statistics->update([

            'completed_quizzes' => $completedQuizzes,

            'correct_answers' => $correctAnswers,

            'wrong_answers' => $wrongAnswers,

            'average_score' => $averageScore,

            'average_percentage' => $averagePercentage,

            'total_study_time' => $totalStudyTime,

            'xp' => $xp,

            'level' => $level,

            'current_streak' => $currentStreak,

            'longest_streak' => max(
                $statistics->longest_streak,
                $currentStreak
            ),

            'last_quiz_at' => optional($lastQuiz)->completed_at,

        ]);

        return $statistics->fresh();
    }
}
