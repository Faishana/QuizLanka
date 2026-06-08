<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Material extends Model
{
    use HasFactory;



    protected $fillable = [

        'uuid',

        'grade_id',

        'subject_id',

        'lesson_id',

        'uploaded_by',

        'title',

        'file_name',

        'file_path',

        'file_type',

        'file_size',

        'extracted_text',

        'processing_status',

        'processed_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($material) {

            $material->uuid = Str::uuid();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function chunks()
    {
        return $this->hasMany(MaterialChunk::class);
    }

}
