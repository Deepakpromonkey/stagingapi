<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dt_payments_loads', function (Blueprint $table) {
            $table->id();
            
            $table->foreignUuid('transaction_id')->constrained('dt_payments', 'uuid');

            $table->string('load_ref', 100);
            $table->string('carrier_invoice', 255);
            $table->string('origin', 255);
            $table->string('destination', 255);
            $table->date('pickup_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('equipment', 255);
            $table->string('commodity', 255);
            $table->double('weight')->nullable();
            $table->double('linehaul_rate')->nullable();
            $table->string('accessorials', 255);
            $table->double('total_to_carrier')->nullable();
            $table->string('rate_confirmation', 255);
            $table->string('pod', 255);
            $table->string('added_by', 50);
            $table->string('added_by_type', 50);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dt_payments_loads');
    }
};