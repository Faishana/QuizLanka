<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    protected $fillable = [
        'user_id',
        'subject_id',
        'total_questions',
        'correct_answers',
        'wrong_answers',
        'score',
        'started_at',
        'completed_at',
    ];

    public function answers()
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
