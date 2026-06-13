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
    ];

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }
}
