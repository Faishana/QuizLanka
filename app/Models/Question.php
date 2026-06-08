<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'material_id',
        'grade_id',
        'subject_id',
        'lesson_id',
        'question_text',
        'question_type',
        'difficulty',
        'correct_answer',
        'explanation',
        'is_ai_generated',
        'source_type',
        'status',
    ];

    protected $hidden = [
        'correct_answer'
    ];
    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

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

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
