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
        Schema::table('users', function (Blueprint $table) {

            $table->foreignId('grade_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('school')->nullable();

            $table->string('district')->nullable();

            $table->string('preferred_medium')->nullable();

            $table->string('target_exam')->nullable();

            $table->string('profile_image')->nullable();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
