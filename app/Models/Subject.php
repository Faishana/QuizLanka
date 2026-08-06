<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Models\Grade;
use App\Models\User;
use App\Models\Material;
use App\Models\Question;

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

    public function grades()
    {
        return $this->belongsToMany(
            Grade::class,
            'grade_subjects'
        )->withTimestamps();
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function questions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Question::class,
            Material::class,
            'subject_id',   // Foreign key on materials table
            'material_id',  // Foreign key on questions table
            'id',           // Local key on subjects table
            'id'            // Local key on materials table
        );
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_subjects'
        )->withTimestamps();
    }
}
