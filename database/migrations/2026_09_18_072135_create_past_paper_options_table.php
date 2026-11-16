<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_paper_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('past_paper_question_id')
                ->constrained('past_paper_questions')
                ->cascadeOnDelete();

            $table->string('option_key', 1);

            $table->text('option_text');

            $table->timestamps();

            $table->unique([
                'past_paper_question_id',
                'option_key',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('past_paper_options');
    }
};
