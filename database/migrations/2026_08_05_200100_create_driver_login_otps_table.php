<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone sign-in codes for the driver app.
 *
 * Drivers have no password — the phone is the identity, so proving control of
 * it is the whole authentication. Mirrors the carrier OTP tables: the code is
 * hashed, attempts are counted so a six digit code cannot be brute forced, and
 * rows are keyed by phone rather than driver so a first-time sign-in works
 * before any driver record exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_login_otps', function (Blueprint $table) {
            $table->id();

            $table->string('phone_e164', 20)->index();

            // Hashed, never the digits themselves.
            $table->string('code_hash');

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            $table->index(['phone_e164', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_login_otps');
    }
};
