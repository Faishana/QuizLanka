<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_otps', function (Blueprint $table) {

            $table->id();

            $table->string('email')->index();

            // Store hashed OTP, not plain OTP
            $table->string('otp');

            $table->timestamp('expires_at');

            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');
    }
};
