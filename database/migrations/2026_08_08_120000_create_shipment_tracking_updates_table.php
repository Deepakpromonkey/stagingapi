<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Send Updates To" is a repeater in the UI — the broker can schedule
     * several tracking-update windows on one shipment. The three scalar
     * columns on `shipments` could only ever hold the first row, so each
     * row now gets its own record here.
     */
    public function up(): void
    {
        Schema::create('shipment_tracking_updates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shipment_id')
                ->constrained()
                ->cascadeOnDelete();

            // Position of the row in the UI repeater, 1-based.
            $table->unsignedInteger('sequence')->default(1);

            $table->dateTime('date_time')->nullable();
            $table->string('tracking_days')->nullable();
            $table->string('interval')->nullable();

            $table->timestamps();

            $table->index(['shipment_id', 'sequence']);

            // The mailer will scan for windows that are now due.
            $table->index('date_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_updates');
    }
};
