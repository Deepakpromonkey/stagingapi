<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ELD step's state on the onboarding request.
 *
 * `eld_skipped_at` is its own timestamp for the same reason the ID and bank
 * skips are: "declined to connect an ELD" and "has not reached that step" are
 * different facts, and a broker deciding whether to tender a load needs to tell
 * them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->foreignId('eld_connection_id')
                ->nullable()
                ->after('bank_skipped_at')
                ->constrained('eld_connections')
                ->nullOnDelete();

            /*
            | The CSRF nonce handed to Terminal when the Link page is opened and
            | checked when the carrier comes back. Single use: cleared on the
            | exchange, so a replayed return URL finds nothing to match.
            */
            $table->string('eld_link_state', 64)->nullable()->after('eld_connection_id');
            $table->timestamp('eld_link_state_at')->nullable()->after('eld_link_state');

            $table->timestamp('eld_connected_at')->nullable()->after('eld_link_state_at');
            $table->timestamp('eld_skipped_at')->nullable()->after('eld_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropForeign(['eld_connection_id']);
            $table->dropColumn([
                'eld_connection_id',
                'eld_link_state',
                'eld_link_state_at',
                'eld_connected_at',
                'eld_skipped_at',
            ]);
        });
    }
};
