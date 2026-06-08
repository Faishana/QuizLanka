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
        Schema::create('material_chunks', function (Blueprint $table) {

            $table->id();

            $table->foreignId('material_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->integer('chunk_order');

            $table->string('title')->nullable();

            $table->longText('content');

            $table->integer('word_count')->default(0);

            $table->enum('status', [
                'pending',
                'processed',
                'failed'
            ])->default('pending');

            $table->timestamps();

            $table->index('material_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_chunks');
    }
};
