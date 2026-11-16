<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Battle extends Model
{
    use HasFactory;

    protected $fillable = [
        'challenger_id',
        'opponent_id',
        'grade_id',
        'subject_id',
        'status',
        'expires_at',
        'question_count',
        'duration_seconds',
        'challenger_score',
        'opponent_score',
        'winner_id',
        'accepted_at',
        'started_at',
        'ends_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'question_count' => 'integer',
            'duration_seconds' => 'integer',
            'challenger_score' => 'integer',
            'opponent_score' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function challenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'challenger_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opponent_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BattleQuestion::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(BattleAnswer::class);
    }


}
