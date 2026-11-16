<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Canonical values: 'Student' | 'Government Exam Candidate'
            $table->string('user_category')
                ->default('Student')
                ->after('email');

            $table->index('user_category');
        });

        // Make grade_id and school nullable so non-students can register.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('grade_id')->nullable()->change();
            $table->string('school')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['user_category']);
            $table->dropColumn('user_category');
        });
    }
};
