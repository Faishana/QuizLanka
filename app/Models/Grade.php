<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Grade extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'category',
        'sort_order',
        'is_active',
        'created_by' // 👈 Add this
    ];

    public function subjects()
    {
        return $this->belongsToMany(
            Subject::class,
            'grade_subjects'
        )->withTimestamps();
    }
}
