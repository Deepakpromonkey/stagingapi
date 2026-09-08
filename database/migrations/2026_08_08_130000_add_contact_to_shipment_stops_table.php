<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who to reach at a stop.
 *
 * The driver app verifies loading and delivery with a code texted to the dock,
 * not to the driver — that is what makes the code evidence the driver was
 * actually there. Without a number on the stop there is nowhere to send it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_stops', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('stop_name');
            $table->string('contact_phone', 30)->nullable()->after('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_stops', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_phone']);
        });
    }
};
