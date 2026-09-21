<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ELD-tracked loads.
 *
 * An ELD load has no trip sheet — origin and destination are two strings on the
 * shipment itself, not stops, because the truck's position comes from the
 * provider rather than from a driver arriving somewhere and pressing a button.
 *
 * Both the local row id and Terminal's own id are kept for the chosen vehicle
 * and driver. The FK is what the UI reads; the terminal_id is what the location
 * poller matches on, since eld_locations is keyed by vehicle_terminal_id and a
 * join through eld_vehicles on every poll would be a wasted round trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('origin')->nullable()->after('pro_number');
            $table->string('destination')->nullable()->after('origin');

            $table->foreignId('eld_connection_id')->nullable()->after('tracking_number');

            $table->foreign('eld_connection_id', 'shipments_eld_connection_fk')
                ->references('id')->on('eld_connections')
                ->nullOnDelete();

            $table->foreignId('eld_vehicle_id')->nullable()->after('eld_connection_id');
            $table->foreignId('eld_driver_id')->nullable()->after('eld_vehicle_id');

            $table->string('eld_vehicle_terminal_id', 64)->nullable()->after('eld_driver_id');
            $table->string('eld_driver_terminal_id', 64)->nullable()->after('eld_vehicle_terminal_id');

            // When the broker pressed Start, and when tracking stopped. Both
            // null on a draft; the poller reads the first and writes nothing
            // until it is set.
            $table->timestamp('eld_tracking_started_at')->nullable()->after('eld_driver_terminal_id');
            $table->timestamp('eld_tracking_stopped_at')->nullable()->after('eld_tracking_started_at');

            // How the poller finds its work: live ELD loads, grouped by
            // connection. Without this it is a full scan of shipments every
            // minute forever.
            $table->index(
                ['tracking_method', 'status', 'eld_connection_id'],
                'shipments_eld_polling_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_eld_polling_index');
            $table->dropForeign('shipments_eld_connection_fk');

            $table->dropColumn([
                'origin',
                'destination',
                'eld_connection_id',
                'eld_vehicle_id',
                'eld_driver_id',
                'eld_vehicle_terminal_id',
                'eld_driver_terminal_id',
                'eld_tracking_started_at',
                'eld_tracking_stopped_at',
            ]);
        });
    }
};