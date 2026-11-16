<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PastPaperOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'past_paper_question_id',
        'option_key',
        'option_text',
    ];

    public function question()
    {
        return $this->belongsTo(PastPaperQuestion::class, 'past_paper_question_id');
    }
}
