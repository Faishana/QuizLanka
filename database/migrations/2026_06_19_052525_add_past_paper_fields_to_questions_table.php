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

            $table->integer('paper_year')->nullable();

            $table->string('paper_name')->nullable();

        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {

            $table->dropColumn([
                'paper_year',
                'paper_name'
            ]);

        });
    }
};
