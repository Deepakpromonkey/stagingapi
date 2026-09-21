<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ELD step's footprint on an onboarding request.
 *
 * Skipping gets its own timestamp for the same reason the government ID and
 * bank steps do: "declined to connect an ELD" and "has not reached that step
 * yet" are different facts, and a broker deciding whether to tender a load
 * needs to tell them apart.
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

            // Round-trips through Terminal Link to prove the redirect coming
            // back is the one we sent. Cleared once it has been spent.
            $table->string('eld_link_state', 64)->nullable()->after('eld_connection_id');

            $table->timestamp('eld_connected_at')->nullable()->after('eld_link_state');
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
                'eld_connected_at',
                'eld_skipped_at',
            ]);
        });
    }
};
