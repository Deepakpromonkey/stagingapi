<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pickup/delivery windows and geocoding for ELD loads.
 *
 * An ELD load has no `shipment_stops` rows — origin/destination are two
 * strings on the shipment itself. These columns extend that same shape rather
 * than introducing stops for a tracking method that deliberately doesn't have
 * them: pickup/delivery timing mirrors shipment_stops' own start_date /
 * start_time / start_timezone columns exactly (same types, same "three plain
 * strings, no combined timestamp" shape), and the lat/lng columns mirror
 * shipment_stops.latitude/longitude — both captured from the same Google
 * Places Autocomplete component, just with nowhere else to land them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('origin_lat', 10, 7)->nullable()->after('origin');
            $table->decimal('origin_lng', 10, 7)->nullable()->after('origin_lat');

            $table->decimal('destination_lat', 10, 7)->nullable()->after('destination');
            $table->decimal('destination_lng', 10, 7)->nullable()->after('destination_lat');

            $table->date('pickup_date')->nullable()->after('destination_lng');
            $table->string('pickup_time')->nullable()->after('pickup_date');
            $table->string('pickup_timezone')->nullable()->after('pickup_time');

            $table->date('delivery_date')->nullable()->after('pickup_timezone');
            $table->string('delivery_time')->nullable()->after('delivery_date');
            $table->string('delivery_timezone')->nullable()->after('delivery_time');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'origin_lat',
                'origin_lng',
                'destination_lat',
                'destination_lng',
                'pickup_date',
                'pickup_time',
                'pickup_timezone',
                'delivery_date',
                'delivery_time',
                'delivery_timezone',
            ]);
        });
    }
};
