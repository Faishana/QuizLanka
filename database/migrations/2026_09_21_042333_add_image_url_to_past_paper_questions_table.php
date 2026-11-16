<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('past_paper_questions', function (Blueprint $table) {
            $table->text('image_url')->nullable()->after('question_text');
        });
    }

    public function down(): void
    {
        Schema::table('past_paper_questions', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
