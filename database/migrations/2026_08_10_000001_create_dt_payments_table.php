<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dt_payments', function (Blueprint $table) {
            $table->id();
            
            $table->uuid('uuid')->unique('dt_payments_row_id_unique');

            $table->foreignUuid('broker_id')
                    ->constrained('users', 'uuid')
                    ->restrictOnDelete();

            $table->foreignUuid('load_id')
                    ->nullable()
                    ->constrained('shipments', 'uuid')
                    ->restrictOnDelete();

            $table->string('carrier_id', 50)->index('dt_payments_carrier_id_index')->nullable();

            $table->string('payment_ref', 100)->nullable();

            $table->string('carrier_name', 255)->nullable();

            $table->enum('source', ['AUTO', 'MANUAL']);
            $table->double('amount')->nullable();
            $table->double('tax')->nullable();
            $table->double('card_fee')->nullable();
            $table->double('fee')->nullable();
            $table->string('payment_method', 10)->nullable();
            $table->string('payment_method_id', 100)->nullable();
            $table->string('payment_method_label', 255)->nullable();
            $table->string('payment_id', 255)->nullable();
            $table->string('transfer_id', 255)->nullable();
            $table->double('transferred_amount')->nullable();
            $table->dateTime('transfer_date')->nullable();
            $table->string('transferred_by', 255)->nullable();

            $table->enum('stage', ['in_hold', 'ready', 'paid_out', 'disputed', 'refunded']);
            $table->enum('status', ['init', 'review', 'paid', 'hold', 'pod_verified', 'refund', 'cancelled']);

            $table->enum('payment_status', ['init', 'hold', 'paid', 'refunded', 'cancelled']);
            
            $table->dateTime('payment_date');

            $table->dateTime('stripe_payment_date')->nullable();
            $table->string('stripe_transaction_id', 255)->nullable();
            $table->string('stripe_status', 100)->nullable();
            $table->text('stripe_response')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dt_payments');
    }
};