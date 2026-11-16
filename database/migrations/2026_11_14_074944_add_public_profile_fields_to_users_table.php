
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_profile_token')
                ->nullable()
                ->unique();

            $table->boolean('public_profile_enabled')
                ->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_profile_token']);
            $table->dropColumn([
                'public_profile_token',
                'public_profile_enabled',
            ]);
        });
    }
};
