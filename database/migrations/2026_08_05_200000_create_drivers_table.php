<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver, identified by their phone number.
 *
 * Brokers already type a driver's number into `shipments.driver_phone_1..3`, so
 * the phone is the only identifier the two sides reliably share. Keying drivers
 * on it means a driver can sign in to the app and immediately see the shipments
 * they were named on, with no extra step from the broker and no change to how
 * shipments are created.
 *
 * `phone_e164` is the match key and is stored digits-only with a leading +, so
 * "(555) 010-2020" and "+15550102020" resolve to the same driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('phone_e164', 20)->unique();

            $table->string('name')->nullable();

            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();

            // Set when a driver should no longer be able to sign in, without
            // losing the message history attached to them.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
