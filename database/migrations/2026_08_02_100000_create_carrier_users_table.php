<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login accounts for carriers who have finished onboarding.
     *
     * Deliberately a separate table from `users`: a carrier is not a member of
     * the broker's company, must never appear in the team list, and holds no
     * role or permission in the broker application. The two sides only meet
     * through carrier_connect_requests.
     *
     * The account is keyed on email alone, not on (broker company, email) —
     * one trucking company that onboards with three brokers gets one login,
     * not three.
     */
    public function up(): void
    {
        Schema::create('carrier_users', function (Blueprint $table) {

            $table->id();

            $table->uuid('uuid')->unique();

            $table->string('email')->unique();

            $table->string('password');

            // The account is provisioned with a system-generated password, so
            // the carrier is held to a change before they can use anything.
            $table->boolean('must_change_password')->default(true);

            // Snapshot of the carrier at the time the account was created. The
            // authoritative record still lives in the external carrier
            // database; this is only what we greet them with.
            $table->string('legal_name')->nullable();
            $table->string('dot_number', 50)->nullable();
            $table->string('phone', 30)->nullable();

            $table->boolean('status')->default(true);

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_password_changed_at')->nullable();

            $table->timestamps();

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_users');
    }
};
