<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {

            $table->id();

            $table->foreignId('grade_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');

            $table->string('slug');

            $table->string('icon')->nullable();

            $table->string('color')->nullable();

            $table->text('description')->nullable();

            $table->integer('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('grade_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
