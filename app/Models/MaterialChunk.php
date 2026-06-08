<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialChunk extends Model
{
    protected $fillable = [

        'material_id',

        'chunk_order',

        'title',

        'content',

        'word_count',

        'status',
    ];

    public function material()
    {
        return $this->belongsTo(
            Material::class
        );
    }
}
