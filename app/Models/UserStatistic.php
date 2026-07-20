<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserStatistic extends Model
{
    protected $fillable = [

        'user_id',

        'completed_quizzes',

        'correct_answers',

        'wrong_answers',

        'average_score',

        'average_percentage',

        'total_study_time',

        'xp',

        'level',

        'current_streak',

        'longest_streak',

        'national_rank',

        'school_rank',

        'district_rank',

        'last_quiz_at',

    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
