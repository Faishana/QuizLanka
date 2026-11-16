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
        Schema::create('past_paper_quiz_answers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quiz_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('past_paper_question_id')
                ->constrained('past_paper_questions')
                ->cascadeOnDelete();

            $table->string('selected_answer', 1)->nullable();

            $table->string('correct_answer', 1);

            $table->boolean('is_correct')->default(false);

            $table->boolean('is_skipped')->default(false);

            $table->timestamps();

            $table->unique([
                'quiz_id',
                'past_paper_question_id'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('past_paper_quiz_answers');
    }
};
