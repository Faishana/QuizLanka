<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    protected $fillable = [
        'quiz_id',
        'question_id',
        'selected_option_id',
        'correct_option_id',
        'is_correct',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    public function selectedOption()
    {
        return $this->belongsTo(
            QuestionOption::class,
            'selected_option_id'
        );
    }

    public function correctOption()
    {
        return $this->belongsTo(
            QuestionOption::class,
            'correct_option_id'
        );
    }
}
