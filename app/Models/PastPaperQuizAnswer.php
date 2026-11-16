<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PastPaperQuizAnswer extends Model
{
    protected $fillable = [
        'quiz_id',
        'past_paper_question_id',
        'selected_answer',
        'correct_answer',
        'is_correct',
        'is_skipped',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'is_skipped' => 'boolean',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function question()
    {
        return $this->belongsTo(
            PastPaperQuestion::class,
            'past_paper_question_id'
        );
    }
}
