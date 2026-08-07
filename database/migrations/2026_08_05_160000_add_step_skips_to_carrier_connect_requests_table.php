<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a carrier move past the government ID and bank steps without completing
 * them.
 *
 * Recorded as their own timestamps rather than by leaving the verification
 * columns null, because "chose to skip" and "has not got to it yet" are
 * different facts and the broker needs to tell them apart when deciding whether
 * to tender a load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->timestamp('identity_skipped_at')->nullable()->after('didit_registration_ip');
            $table->timestamp('bank_skipped_at')->nullable()->after('stripe_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropColumn(['identity_skipped_at', 'bank_skipped_at']);
        });
    }
};
