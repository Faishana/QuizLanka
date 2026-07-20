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
        Schema::table('questions', function (Blueprint $table) {

            $table->integer('question_number')->nullable()->after('chunk_order');

            $table->string('question_image')->nullable()->after('question_text');

            $table->string('page_image')->nullable()->after('question_image');

            $table->decimal('ai_confidence', 5, 2)
                ->nullable()
                ->after('correct_answer');

            $table->enum('verification_status', [
                'pending',
                'ai_verified',
                'manual_verified'
            ])->default('pending')->after('ai_confidence');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            //
        });
    }
};
