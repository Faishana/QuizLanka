
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('challenger_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('opponent_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('grade_id')
                ->constrained('grades')
                ->restrictOnDelete();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete();

            // pending, accepted, active, completed,
            // declined, expired, cancelled
            $table->string('status', 20)->default('pending');

            $table->unsignedSmallInteger('question_count')
                ->default(10);

            $table->unsignedInteger('duration_seconds')
                ->default(300);

            $table->unsignedInteger('challenger_score')
                ->default(0);

            $table->unsignedInteger('opponent_score')
                ->default(0);

            $table->foreignId('winner_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['challenger_id', 'status']);
            $table->index(['opponent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battles');
    }
};
