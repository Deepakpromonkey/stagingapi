<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three fixes to the addresses a shipment will later be mailed to:
     *
     * 1. `email_updates_to` holds a JSON array in a VARCHAR(255). Six ordinary
     *    corporate addresses serialise to 256 characters, so creating the
     *    shipment failed outright (MySQL strict mode, error 1406). Same cliff
     *    on `shipment_stops.alert_emails`. Both become JSON columns.
     * 2. The broker dispatcher's name and email were posted by the frontend
     *    but had nowhere to land.
     * 3. The single-row tracking-update columns are replaced by the
     *    `shipment_tracking_updates` table. They were never populated (the
     *    service read an array as if it were one row), so nothing is lost.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('broker_dispatcher_name')->nullable()->after('team_load');
            $table->string('broker_dispatcher_email')->nullable()->after('broker_dispatcher_name');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->json('email_updates_to')->nullable()->change();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['update_datetime', 'tracking_days', 'tracking_interval']);
        });

        Schema::table('shipment_stops', function (Blueprint $table) {
            $table->json('alert_emails')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_stops', function (Blueprint $table) {
            $table->string('alert_emails')->nullable()->change();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dateTime('update_datetime')->nullable()->after('tracking_start_at');
            $table->string('tracking_days')->nullable()->after('update_datetime');
            $table->string('tracking_interval')->nullable()->after('tracking_days');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('email_updates_to')->nullable()->change();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['broker_dispatcher_name', 'broker_dispatcher_email']);
        });
    }
};
