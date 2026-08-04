<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login codes for the carrier portal.
     *
     * A separate table from `login_otps`, which is keyed to `users` — carriers
     * are a different model entirely and the two must never share a row space.
     */
    public function up(): void
    {
        Schema::create('carrier_login_otps', function (Blueprint $table) {

            $table->id();

            $table->foreignId('carrier_user_id')
                ->constrained('carrier_users')
                ->cascadeOnDelete();

            // Handed to the client in place of the password, so the code can be
            // submitted without re-sending credentials.
            $table->uuid('otp_session')->unique();

            // Hashed, never stored in clear text.
            $table->string('otp');

            $table->timestamp('expires_at');

            $table->unsignedTinyInteger('attempts')->default(0);

            // Carried through the challenge so the device can be trusted on
            // verification without the client having to re-send it.
            $table->string('device_uuid', 100)->nullable();

            $table->ipAddress('ip_address')->nullable();

            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index('carrier_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_login_otps');
    }
};
