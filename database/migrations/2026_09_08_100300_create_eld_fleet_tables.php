<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The synced fleet: what Terminal returns for a connection, normalised.
 *
 * Every table carries the provider's own id in `terminal_id` and is unique on
 * (connection, terminal_id), because syncs re-read overlapping windows and a
 * record that arrives twice must update the row it already wrote rather than
 * duplicate it. `payload` keeps the untouched response for the fields no column
 * has yet — cheaper than a migration every time a provider adds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eld_vehicles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('eld_connection_id')
                ->constrained('eld_connections')
                ->cascadeOnDelete();

            $table->string('terminal_id', 64);

            $table->string('name')->nullable();
            $table->string('vin', 20)->nullable()->index();
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('year', 4)->nullable();
            $table->string('license_plate', 20)->nullable();
            $table->string('status', 30)->nullable();

            $table->json('payload')->nullable();

            // Terminal's ingestion timestamp for this record — what the next
            // incremental pass sends back as `modifiedAfter`.
            $table->timestamp('terminal_modified_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id'], 'eld_vehicles_connection_terminal_unique');
        });

        Schema::create('eld_drivers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('eld_connection_id')
                ->constrained('eld_connections')
                ->cascadeOnDelete();

            $table->string('terminal_id', 64);

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('license_number', 40)->nullable();
            $table->string('license_state', 5)->nullable();
            $table->string('status', 30)->nullable();

            $table->json('payload')->nullable();
            $table->timestamp('terminal_modified_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id'], 'eld_drivers_connection_terminal_unique');
        });

        /*
        | Hours of service. This is the one that decides whether a driver was
        | legal to run a load, so `started_at` / `ended_at` are real columns and
        | indexed — every question asked of this table is asked over a window.
        */
        Schema::create('eld_hos_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('eld_connection_id')
                ->constrained('eld_connections')
                ->cascadeOnDelete();

            $table->string('terminal_id', 64);
            $table->string('driver_terminal_id', 64)->nullable()->index();
            $table->string('vehicle_terminal_id', 64)->nullable()->index();

            // off_duty | sleeper_berth | driving | on_duty, as the provider
            // reports it — stored verbatim rather than mapped, so a provider
            // adding a status does not silently become one of the four.
            $table->string('duty_status', 30)->nullable();

            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->json('payload')->nullable();
            $table->timestamp('terminal_modified_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id'], 'eld_hos_logs_connection_terminal_unique');
        });

        /*
        | Vehicle positions. Unlike the tables above there is no stable record
        | id to dedupe on — a ping is identified by its vehicle and its instant
        | — so that pair is the unique key and a re-read of the lookback window
        | overwrites rather than duplicates.
        */
        Schema::create('eld_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('eld_connection_id')
                ->constrained('eld_connections')
                ->cascadeOnDelete();

            $table->string('vehicle_terminal_id', 64);

            $table->timestamp('located_at');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_mph', 6, 2)->nullable();
            $table->decimal('heading_degrees', 6, 2)->nullable();
            $table->unsignedBigInteger('odometer_miles')->nullable();
            $table->string('description')->nullable();

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique(
                ['eld_connection_id', 'vehicle_terminal_id', 'located_at'],
                'eld_locations_connection_vehicle_time_unique'
            );

            // How the shipment tracking view reads it: one vehicle, newest first.
            $table->index(['eld_connection_id', 'located_at'], 'eld_locations_connection_time_index');
        });

        /*
        | Where each resource's last sync got to.
        |
        | Without this every pass is a full re-pull, which is both slow and the
        | most expensive possible way to be wrong about the bill.
        */
        Schema::create('eld_sync_checkpoints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('eld_connection_id')
                ->constrained('eld_connections')
                ->cascadeOnDelete();

            // vehicles | drivers | hos | locations
            $table->string('resource', 20);

            // Sent as `modifiedAfter` (ingestion time) or `startAt` (record
            // time) on the next run, depending on what the resource supports.
            $table->timestamp('synced_through')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_record_count')->default(0);

            $table->timestamps();

            $table->unique(['eld_connection_id', 'resource'], 'eld_sync_checkpoints_connection_resource_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eld_sync_checkpoints');
        Schema::dropIfExists('eld_locations');
        Schema::dropIfExists('eld_hos_logs');
        Schema::dropIfExists('eld_drivers');
        Schema::dropIfExists('eld_vehicles');
    }
};
