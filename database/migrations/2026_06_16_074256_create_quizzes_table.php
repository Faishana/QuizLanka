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
        Schema::create('quizzes', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('grade_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('quiz_type', [
                'practice',
                'timed',
                'mock_exam',
                'daily_challenge',
                'weak_area'
            ])->default('practice');

            $table->unsignedInteger('total_questions')->default(0);

            $table->unsignedInteger('correct_answers')->default(0);

            $table->unsignedInteger('wrong_answers')->default(0);

            $table->decimal('score', 5, 2)->default(0);

            $table->decimal('percentage', 5, 2)->default(0);

            $table->unsignedInteger('duration_seconds')->default(0);

            $table->boolean('is_completed')->default(false);

            $table->timestamp('started_at')->nullable();

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
