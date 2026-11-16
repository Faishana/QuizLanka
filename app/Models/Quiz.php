<?php

namespace App\Models;

use App\Models\PastPaper;
use App\Models\PastPaperQuizAnswer;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    protected $fillable = [
        'user_id',
        'grade_id',
        'subject_id',
        'past_paper_id',
        'quiz_type',
        'total_questions',
        'correct_answers',
        'wrong_answers',
        'score',
        'percentage',
        'duration_seconds',
        'is_completed',
        'started_at',
        'expires_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_seconds' => 'integer',
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

    public function pastPaperAnswers()
    {
        return $this->hasMany(PastPaperQuizAnswer::class);
    }

    public function pastPaper()
    {
        return $this->belongsTo(PastPaper::class);
    }
}
