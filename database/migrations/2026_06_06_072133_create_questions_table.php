<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */

            $table->foreignId('material_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('grade_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('material_chunk_id')
                ->nullable();

            $table->integer('chunk_order')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Question Content
            |--------------------------------------------------------------------------
            */

            $table->longText('question_text');

            $table->enum('question_type', [

                'mcq',
                'true_false',
                'short_answer',
            ])->default('mcq');

            /*
            |--------------------------------------------------------------------------
            | Difficulty
            |--------------------------------------------------------------------------
            */

            $table->enum('difficulty', [

                'easy',
                'medium',
                'hard',
            ])->default('medium');

            /*
            |--------------------------------------------------------------------------
            | Answers
            |--------------------------------------------------------------------------
            */

            $table->string('correct_answer')
                ->nullable();

            $table->longText('explanation')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | AI Metadata
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_ai_generated')
                ->default(false);

            $table->string('source_type')
                ->nullable();

            $table->integer('source_page')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [

                'draft',
                'published',
                'archived',
            ])->default('draft');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
