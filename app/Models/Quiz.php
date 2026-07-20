<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    protected $fillable = [
        'user_id',
        'grade_id',
        'subject_id',
        'quiz_type',
        'total_questions',
        'correct_answers',
        'wrong_answers',
        'score',
        'percentage',
        'duration_seconds',
        'is_completed',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_completed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function answers()
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class);
    }
}
