<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Grade extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'category',
        'sort_order',
        'is_active',
        'created_by',
    ];

    /**
     * Subjects assigned to this grade.
     * A/L subjects can belong to multiple streams.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(
            Subject::class,
            'grade_subjects',
            'grade_id',
            'subject_id'
        )
        ->withPivot('al_streams')
        ->withTimestamps();
    }
}
