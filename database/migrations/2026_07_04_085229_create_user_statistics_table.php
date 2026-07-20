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
        Schema::create('user_statistics', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Quiz Statistics
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('completed_quizzes')->default(0);

            $table->unsignedInteger('correct_answers')->default(0);

            $table->unsignedInteger('wrong_answers')->default(0);

            $table->decimal('average_score', 6, 2)->default(0);

            $table->decimal('average_percentage', 5, 2)->default(0);

            $table->unsignedInteger('total_study_time')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Gamification
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('xp')->default(0);

            $table->unsignedInteger('level')->default(1);

            $table->unsignedInteger('current_streak')->default(0);

            $table->unsignedInteger('longest_streak')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Rankings
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('national_rank')->nullable();

            $table->unsignedInteger('school_rank')->nullable();

            $table->unsignedInteger('district_rank')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Activity
            |--------------------------------------------------------------------------
            */

            $table->timestamp('last_quiz_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_statistics');
    }
};
