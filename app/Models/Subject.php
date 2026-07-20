<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'grade_id',
        'name',
        'slug',
        'icon',
        'color',
        'description',
        'sort_order',
        'is_active',
    ];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_subjects'
        )->withTimestamps();
    }
}
