<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How often the driver app should report its position on this load.
     *
     * Per shipment rather than per driver: the broker owns the load and is the
     * one paying for the visibility, and a driver hauling for two brokers must
     * not have one broker's choice of rate applied to the other's freight. The
     * driver-level app_drivers.tracking_interval_seconds column that predates
     * this was never writable by anyone, so every load ran at the 300s default.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedInteger('tracking_interval_seconds')
                ->default(300)
                ->after('tracking_start_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('tracking_interval_seconds');
        });
    }
};
