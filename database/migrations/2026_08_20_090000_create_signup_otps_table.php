<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    | Signup verification codes, for the email address and phone number a
    | visitor enters before they have an account.
    |
    | Deliberately not login_otps or password_reset_otps: both of those hang
    | off user_id, and the whole point here is that no user row exists yet.
    | The address itself is what the code is bound to, and `destination` is
    | re-checked against the submitted field at signup so a code proved for
    | one address cannot be presented for another.
    */
    public function up(): void
    {
        Schema::create('signup_otps', function (Blueprint $table) {
            $table->id();

            $table->uuid('otp_session')->unique();

            // 'email' or 'phone'. A string rather than an enum so adding a
            // channel later is a code change, not a migration.
            $table->string('channel', 10);

            // The address the code was sent to: an email, or a phone in E.164.
            $table->string('destination');

            // Hashed, never stored in clear text.
            $table->string('otp');

            // Issued only after the OTP is verified; sha256 of the token the
            // client holds, so it can be looked up without a table scan.
            $table->string('verification_token', 64)->nullable()->unique();

            $table->timestamp('expires_at');

            $table->timestamp('verified_at')->nullable();

            // Set when signup spends the token. A proof is worth one account,
            // so a replayed token cannot open a second.
            $table->timestamp('consumed_at')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            // Resending looks up the newest live code for an address.
            $table->index(['channel', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_otps');
    }
};
