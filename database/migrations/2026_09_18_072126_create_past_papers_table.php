<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_papers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('grade_id')
                ->constrained('grades')
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('year');

            $table->string('medium', 10);

            $table->string('title');

            $table->unsignedTinyInteger('paper_number')
                ->nullable();

            $table->unsignedInteger('total_questions')
                ->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index([
                'grade_id',
                'subject_id',
                'year',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('past_papers');
    }
};
