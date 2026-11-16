<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PastPaper extends Model
{
    use HasFactory;

    protected $fillable = [
        'grade_id',
        'subject_id',
        'al_stream',
        'year',
        'time_limit_minutes',
        'medium',
        'title',
        'paper_number',
        'total_questions',
        'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'time_limit_minutes' => 'integer',
        'paper_number' => 'integer',
        'total_questions' => 'integer',
    ];

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function questions()
    {
        return $this->hasMany(PastPaperQuestion::class)
            ->orderBy('question_number');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
