<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'grade_category',
        'icon',
        'color',
        'description',
        'sort_order',
        'is_active',
    ];

    public function grades(): BelongsToMany
    {
        return $this->belongsToMany(
            Grade::class,
            'grade_subjects'
        )
        ->withPivot('al_streams')
        ->withTimestamps();
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function pastPapers(): HasMany
    {
        return $this->hasMany(PastPaper::class);
    }

    public function questions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Question::class,
            Material::class,
            'subject_id',
            'material_id',
            'id',
            'id'
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_subjects'
        )->withTimestamps();
    }
}
