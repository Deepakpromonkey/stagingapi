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
        Schema::create('shipment_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->integer('stop_number');  
            $table->string('stop_type'); // 'Pickup' or 'Delivery'
            $table->string('stop_name')->nullable();
            $table->string('address');  
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('country')->nullable();

            $table->decimal('latitude', 10, 7)->nullable(); 
            $table->decimal('longitude', 10, 7)->nullable();
            
            // Timing
            $table->date('start_date')->nullable();
            $table->string('start_time')->nullable();
            $table->string('start_timezone')->nullable();
            $table->date('end_date')->nullable();
            $table->string('end_time')->nullable();
            $table->string('end_timezone')->nullable();
            
            // Comms & Events
            $table->text('comment_to_driver')->nullable();
            $table->string('alert_emails')->nullable();
            $table->json('events')->nullable();  

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipment_stops');
    }
};
