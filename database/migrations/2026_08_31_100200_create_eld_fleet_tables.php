<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The synced fleet: vehicles, drivers, hours-of-service logs, and where each
 * truck was last seen.
 *
 * Every table follows the same shape — Terminal's id as the natural key,
 * a handful of extracted columns for the things the UI filters and sorts on,
 * and `payload` holding the untouched common-model object. Terminal documents
 * per-provider field coverage precisely because a Geotab fleet and a Motive
 * fleet do not populate the same fields; pinning every field to a column would
 * silently drop whatever we had not anticipated.
 *
 * Locations are latest-per-vehicle rather than a history. A trail is unbounded
 * and Terminal already serves it on demand from
 * /vehicles/{id}/locations/historical; what the dashboard needs is one pin per
 * truck, which upserts and stays small.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eld_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id')->constrained('eld_connections')->cascadeOnDelete();

            $table->string('terminal_id');            // vcl_...
            $table->string('source_id')->nullable();  // the provider's own id
            $table->string('provider')->nullable();
            $table->string('status')->nullable();

            $table->string('vin')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('license_plate_state', 8)->nullable();
            $table->string('license_plate_number')->nullable();

            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id']);
        });

        Schema::create('eld_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id')->constrained('eld_connections')->cascadeOnDelete();

            $table->string('terminal_id');            // drv_...
            $table->string('source_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('status')->nullable();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('license_number')->nullable();
            $table->string('license_state', 8)->nullable();

            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id']);
        });

        Schema::create('eld_hos_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id')->constrained('eld_connections')->cascadeOnDelete();

            $table->string('terminal_id');
            $table->string('source_id')->nullable();
            $table->string('provider')->nullable();

            // Duty status: the provider's normalised value (off_duty, sleeper,
            // driving, on_duty and friends). Left a string rather than an enum
            // because the vocabulary is Terminal's to extend, not ours.
            $table->string('status')->nullable();

            $table->string('driver_terminal_id')->nullable();
            $table->string('vehicle_terminal_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->text('remarks')->nullable();

            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id']);

            // "This driver's last 8 days", which is the only way anyone reads
            // these — a compliance view, not a log dump.
            $table->index(['eld_connection_id', 'driver_terminal_id', 'started_at']);
        });

        Schema::create('eld_vehicle_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id')->constrained('eld_connections')->cascadeOnDelete();

            $table->string('vehicle_terminal_id');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->decimal('heading', 8, 2)->nullable();
            $table->string('description')->nullable();

            $table->timestamp('located_at')->nullable();

            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['eld_connection_id', 'vehicle_terminal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eld_vehicle_locations');
        Schema::dropIfExists('eld_hos_logs');
        Schema::dropIfExists('eld_drivers');
        Schema::dropIfExists('eld_vehicles');
    }
};
