<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every carrier portal sign-in attempt, successful or not, with the IP it
     * came from.
     *
     * Written before the outcome is known to be good, so an attempt against an
     * email that has no account is recorded too — the point is to be able to
     * see credential stuffing, not only successful logins. `carrier_user_id` is
     * therefore nullable, and the account is nulled rather than the row removed
     * when a carrier is deleted.
     */
    public function up(): void
    {
        Schema::create('carrier_login_attempts', function (Blueprint $table) {

            $table->id();

            $table->foreignId('carrier_user_id')
                ->nullable()
                ->constrained('carrier_users')
                ->nullOnDelete();

            // As typed. Kept even when it matches no account.
            $table->string('email')->nullable();

            $table->string('outcome', 30);

            $table->ipAddress('ip_address')->nullable();

            $table->string('user_agent')->nullable();

            $table->string('device_uuid', 100)->nullable();

            $table->timestamps();

            $table->index(['carrier_user_id', 'created_at']);
            $table->index(['email', 'created_at']);
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_login_attempts');
    }
};
