
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_answers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('battle_id')
                ->constrained('battles')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('question_id')
                ->constrained('questions')
                ->restrictOnDelete();

            $table->foreignId('selected_option_id')
                ->nullable()
                ->constrained('question_options')
                ->nullOnDelete();

            $table->boolean('is_correct')->default(false);
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            $table->unique([
                'battle_id',
                'user_id',
                'question_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_answers');
    }
};
