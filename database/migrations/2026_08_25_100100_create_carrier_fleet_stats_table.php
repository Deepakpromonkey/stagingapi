<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_fleet_stats', function (Blueprint $table) {
            /*
            | One row per carrier, keyed by DOT number. The carrier itself
            | lives in the external EC2 database, so there is no foreign key —
            | the same arrangement carrier_reports uses.
            |
            | This exists so the profile endpoint never aggregates over the
            | inspections table on the request path. A carrier with 15k
            | inspections would otherwise pay a full scan for two numbers.
            */
            $table->string('dot_number', 20)->primary();

            $table->decimal('avg_power_age', 4, 1)->nullable();
            $table->decimal('avg_trailer_age', 4, 1)->nullable();

            // How many distinct VINs each average is built from. Shown in the
            // UI so a number derived from three trucks is not read as fleetwide.
            $table->unsignedInteger('power_units')->default(0);
            $table->unsignedInteger('trailers')->default(0);

            // Distinct VINs seen for this carrier vs. how many of those resolved
            // to a decoded pattern. The gap is the backfill's remaining work.
            $table->unsignedInteger('vins_total')->default(0);
            $table->unsignedInteger('vins_decoded')->default(0);

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->index('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_fleet_stats');
    }
};
