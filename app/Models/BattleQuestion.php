<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BattleQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'battle_id',
        'question_id',
        'question_order',
    ];

    protected function casts(): array
    {
        return [
            'question_order' => 'integer',
        ];
    }

    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
