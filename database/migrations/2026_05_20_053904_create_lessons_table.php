<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {

            $table->id();

            $table->foreignId('subject_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');

            $table->string('slug');

            $table->text('description')->nullable();

            $table->integer('estimated_time')->nullable();

            $table->integer('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
