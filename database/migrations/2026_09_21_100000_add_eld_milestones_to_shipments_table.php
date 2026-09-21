<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the truck was actually seen at the origin and destination.
 *
 * Set once, by EldTrackingService, the first time a poll lands the vehicle's
 * position within the geofence radius of origin_lat/lng or
 * destination_lat/lng — never cleared afterward, even if the truck later
 * drives back out of the radius (a truck that repositions within a large
 * yard has still arrived; the first crossing is the event that matters).
 *
 * Only ever populated for a shipment whose origin/destination were captured
 * with real coordinates — a broker who typed an address by hand instead of
 * picking it from the autocomplete has nothing to geofence against, and
 * these stay null for that load for its whole life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('arrived_at_origin_at')->nullable()->after('destination_lng');
            $table->timestamp('arrived_at_destination_at')->nullable()->after('arrived_at_origin_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['arrived_at_origin_at', 'arrived_at_destination_at']);
        });
    }
};
