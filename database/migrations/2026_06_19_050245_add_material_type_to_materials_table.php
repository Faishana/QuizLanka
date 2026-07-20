<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {

            $table->enum('material_type', [
                'lesson',
                'past_paper'
            ])->default('lesson');

        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {

            $table->dropColumn('material_type');

        });
    }
};
