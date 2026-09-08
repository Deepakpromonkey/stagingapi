<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A carrier's link to their telematics provider, held through Terminal.
 *
 * Keyed to the CARRIER, not to the onboarding request that produced it. A
 * carrier hauling for three brokers on this platform runs the Link flow three
 * times against the same Samsara account, and Terminal meters the data synced
 * for a connection — so one row per broker would import, and bill for, the same
 * fleet three times over. Which brokers may see the fleet is a separate fact,
 * recorded in eld_connection_grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eld_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | The dedupe key. DOT number rather than carrier_row_id because the
            | carrier table lives in the external FMCSA database and a request
            | may reach the ELD step before it has been matched to a row there.
            */
            $table->string('carrier_dot_number', 20)->index();
            $table->string('carrier_row_id', 40)->nullable()->index();
            $table->string('carrier_legal_name')->nullable();

            $table->string('terminal_connection_id', 64)->unique();

            // What we sent Terminal as `external_id`, kept so a connection seen
            // on a webhook can be traced back without a second lookup.
            $table->string('external_id', 64)->nullable()->index();

            $table->string('provider', 40)->nullable();

            /*
            | Long-lived and equivalent to the carrier's provider credentials,
            | so it is encrypted at rest (see the model's cast) and never
            | exposed through a resource.
            */
            $table->text('connection_token');

            // connected | disconnected | archived
            $table->string('status', 20)->default('connected')->index();

            // pending | running | completed | failed
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_sync_error')->nullable();

            // Denormalised so the onboarding tile can state the fleet size
            // without counting two tables on every poll.
            $table->unsignedInteger('vehicle_count')->default(0);
            $table->unsignedInteger('driver_count')->default(0);

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            /*
            | Archive stops syncing and keeps the history a broker needs to
            | settle a dispute over a load already hauled; delete removes the
            | row outright and is reserved for an explicit erasure request.
            */
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eld_connections');
    }
};
