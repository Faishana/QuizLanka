<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'material_id',
        'grade_id',
        'subject_id',
        'material_chunk_id',
        'chunk_order',
        'question_text',
        'question_type',
        'difficulty',
        'correct_answer',
        'explanation',
        'is_ai_generated',

        'source_type',

        'paper_year',
        'paper_name',

        'source_page',
        'status',
        'created_by' // 👈 Add this if missing
    ];

    protected $hidden = [
        'correct_answer',
    ];

    public function options()
    {
        return $this->hasMany(QuestionOption::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function quizAnswers()
    {
        return $this->hasMany(QuizAnswer::class);
    }
}
