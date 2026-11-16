<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_category')
                ->nullable()
                ->default(null)
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('users')
            ->whereNull('user_category')
            ->update(['user_category' => 'Student']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('user_category')
                ->default('Student')
                ->nullable(false)
                ->change();
        });
    }

};
