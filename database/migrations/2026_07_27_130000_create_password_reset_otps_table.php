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

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->uuid('otp_session')->unique();

            // Hashed, never stored in clear text.
            $table->string('otp');

            // Issued only after the OTP is verified; sha256 of the token the
            // client holds, so it can be looked up without a table scan.
            $table->string('reset_token', 64)->nullable()->unique();

            $table->timestamp('expires_at');

            $table->timestamp('verified_at')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');
    }
};
