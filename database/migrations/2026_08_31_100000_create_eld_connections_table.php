<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per telematics account a carrier has linked through Terminal.
 *
 * The connection belongs to the carrier, not to the onboarding request that
 * happened to collect it — a carrier onboarding with a second broker links the
 * same ELD account, and duplicating the sync per broker would multiply the API
 * calls for identical data. carrier_connect_requests points at this instead of
 * owning it.
 *
 * `payload` holds Terminal's full connection object. Their common model gains
 * fields over time and coverage varies by provider, so the columns here are
 * only the ones something actually queries; the rest stays readable without a
 * migration per field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eld_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Terminal's own id, `conn_...`. The natural key for everything
            // that arrives by webhook, which knows nothing of our ids.
            $table->string('terminal_connection_id')->unique();

            // `con_tkn_...`. Does not expire, and is the only thing that reads
            // this carrier's data — treated as a credential.
            $table->text('connection_token');

            $table->string('provider_code')->nullable();
            $table->string('provider_name')->nullable();

            // Terminal's own lifecycle: connected, disconnected, deleted.
            $table->string('status')->default('connected');

            // What we sent as external_id, so a webhook can be traced back to
            // the onboarding request that started it.
            $table->string('external_id')->nullable()->index();

            $table->string('account_name')->nullable();
            $table->json('dot_numbers')->nullable();

            // Mirrors Terminal's lastSync so the wizard and the broker view can
            // say "still importing" instead of showing an empty fleet.
            $table->string('sync_status')->nullable();
            $table->unsignedTinyInteger('sync_progress')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            $table->json('payload')->nullable();

            $table->timestamps();

            // The scheduler's "what has gone stale" sweep.
            $table->index(['status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eld_connections');
    }
};
