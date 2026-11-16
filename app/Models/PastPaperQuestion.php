<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PastPaperQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'past_paper_id',
        'question_number',
        'question_text',
        'image_url',
        'correct_answer',
        'difficulty',
        'explanation',
        'created_by',
    ];

    protected $casts = [
        'question_number' => 'integer',
    ];

    public function pastPaper()
    {
        return $this->belongsTo(PastPaper::class);
    }

    public function options()
    {
        return $this->hasMany(PastPaperOption::class)
            ->orderBy('option_key');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
