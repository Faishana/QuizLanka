<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_paper_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('past_paper_id')
                ->constrained('past_papers')
                ->cascadeOnDelete();

            $table->unsignedInteger('question_number');

            $table->text('question_text');

            $table->string('correct_answer', 1);

            $table->enum('difficulty', [
                'easy',
                'medium',
                'hard',
            ])->default('medium');

            $table->text('explanation')
                ->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('past_paper_id');

            $table->unique([
                'past_paper_id',
                'question_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('past_paper_questions');
    }
};
