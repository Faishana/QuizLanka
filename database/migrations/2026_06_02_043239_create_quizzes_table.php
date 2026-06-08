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

            $table->foreignId('user_id')->constrained();

            $table->foreignId('subject_id')->nullable()->constrained();

            $table->integer('total_questions')->default(0);

            $table->integer('correct_answers')->default(0);

            $table->integer('wrong_answers')->default(0);

            $table->decimal('score', 5, 2)->default(0);

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
