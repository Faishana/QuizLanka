
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battle_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('battle_id')
                ->constrained('battles')
                ->cascadeOnDelete();

            $table->foreignId('question_id')
                ->constrained('questions')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('question_order');

            $table->timestamps();

            $table->unique(['battle_id', 'question_id']);
            $table->unique(['battle_id', 'question_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_questions');
    }
};
