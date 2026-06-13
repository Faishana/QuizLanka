<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {

            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('grade_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title');

            $table->string('file_name');

            $table->string('file_path');

            $table->string('file_type');

            $table->bigInteger('file_size');

            $table->longText('extracted_text')->nullable();

            $table->string('processing_status')
                ->default('pending');

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('grade_id');
            $table->index('subject_id');
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
