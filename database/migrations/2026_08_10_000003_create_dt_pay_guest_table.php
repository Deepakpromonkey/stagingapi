<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dt_pay_guest', function (Blueprint $table) {
            $table->id();
            
            $table->uuid('uuid')->unique('dt_payments_row_id_unique');

            $table->string('carrier_id', 50)->index('dt_payments_carrier_id_index');
            $table->string('load_id', 50)->index('dt_payments_load_id_index')->nullable();;

            $table->string('payment_ref', 100)->nullable();;
            $table->double('amount')->nullable();;
            $table->double('tax')->nullable();;
            $table->double('card_fee')->nullable();;
            $table->double('fee')->nullable()->nullable();;
            $table->string('carrier_invoice', 255)->nullable();
            $table->string('origin', 255)->nullable();
            $table->string('destination', 255)->nullable();
            $table->dateTime('delivery_date')->nullable();
            $table->string('equipment', 255)->nullable();
            $table->string('rate_confirmation', 255)->nullable();
            $table->string('customer_legal_name', 255)->nullable();
            $table->string('role', 30)->nullable();
            $table->string('broker_mc', 255)->nullable();
            $table->string('ein', 50)->nullable();
            $table->string('contact_name', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('business_address', 255)->nullable();
            $table->string('payment_method', 10)->nullable();
            $table->string('payment_method_id', 100)->nullable();
            $table->string('payment_method_label', 255)->nullable();
            $table->string('payment_id', 255)->nullable();
            $table->string('transfer_id', 255)->nullable();
            $table->double('transferred_amount')->nullable();
            $table->dateTime('transfer_date')->nullable();
            $table->string('transferred_by', 255)->nullable();
            $table->enum('stage', ['pod_verified', 'refunded', 'released', 'paid']);
            $table->enum('status', ['init', 'review', 'paid', 'hold', 'refund', 'cancelled']);
            $table->dateTime('payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dt_pay_guest');
    }
};