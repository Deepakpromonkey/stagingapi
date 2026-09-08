<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {

            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->constrained('users');

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users');

            // Shipment

            $table->string('shipment_no')->unique();

            $table->string('pro_number')->nullable();

            // Carrier

            $table->string('carrier_name')->nullable();
            $table->string('carrier_mc')->nullable();
            $table->string('carrier_dot')->nullable();
            $table->string('carrier_phone')->nullable();
            $table->string('carrier_extension')->nullable();

            // Tracking

            $table->enum('tracking_method', [
                'driver_phone',
                'eld',
                'gps',
            ]);

            $table->string('country_code')->nullable();

            $table->string('tracking_number')->nullable();

            // Driver

            $table->string('truck_number')->nullable();

            $table->string('trailer_number')->nullable();

            $table->string('driver_phone_1')->nullable();

            $table->string('driver_phone_2')->nullable();

            $table->string('driver_phone_3')->nullable();

            $table->enum('driver_type', [
                'company_driver',
                'leased_owner_operator',
                'independent_owner_operator',
                'other_company_driver',
            ])->nullable();

            $table->boolean('team_load')
                ->default(false);

            // Tracking Start

            $table->timestamp('tracking_start_at')
                ->nullable();

            // Notes

            $table->longText('notes')
                ->nullable();

            // Status

            $table->enum('status', [
                'draft',
                'active',
                'completed',
                'cancelled',
            ])->default('draft');

            $table->timestamps();

            $table->index('company_id');

            $table->index('status');

            $table->index('tracking_number');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
