<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a broker send the onboarding invitation somewhere other than the address
 * on the carrier's FMCSA record — but only with the carrier's consent.
 *
 * The alternate address is parked in these columns and nothing is mailed to it.
 * The FMCSA address is asked to approve first; approval promotes the pending
 * address to `carrier_email` and the invitation goes out then. Without this the
 * broker could point a carrier's onboarding — ID documents, bank details, a
 * signed agreement — at an inbox they control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {

            // Where the broker wants the invitation to go. Holds the address
            // only until it is approved, at which point it moves to
            // carrier_email and this is cleared.
            $table->string('pending_email')->nullable()->after('carrier_email');

            // Handed to the carrier's FMCSA inbox as the approval link. Unique
            // and cleared on use, so an approval cannot be replayed.
            $table->string('pending_email_token', 64)->nullable()->unique()->after('pending_email');

            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email_token');
            $table->timestamp('pending_email_approved_at')->nullable()->after('pending_email_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropUnique(['pending_email_token']);

            $table->dropColumn([
                'pending_email',
                'pending_email_token',
                'pending_email_requested_at',
                'pending_email_approved_at',
            ]);
        });
    }
};
