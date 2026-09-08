<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dt_payments_trasactions', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('payment_id')
                ->nullable();
            // No ->after() here: it compiles into the CREATE TABLE column
            // definition, where AFTER is not valid syntax (MariaDB rejects the
            // whole statement with a 1064). It only means anything on ALTER
            // TABLE, and the column is already declared in the position it
            // asked for, so positioning it explicitly bought nothing.
            $table->foreignUuid('guest_payment_id')
                ->nullable()
                ->constrained('dt_pay_guest', 'uuid')
                ->restrictOnDelete();

            $table->string('transaction_label', 255)->nullable();
            $table->string('sub_label', 255)->nullable();
            $table->string('added_by', 50)->nullable();
            $table->string('added_by_type', 50)->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->string('load_ref', 50)->nullable();

            $table->string('load', 50)->nullable();

            $table->string('carrier_dot_number', 255)->nullable();
            $table->string('carrier_name', 255)->nullable();

            $table->tinyInteger('sequence')->default(0);

            $table->string('stripe_transaction_id', 255)->nullable();
            $table->string('stripe_payment_status', 100)->nullable();
            $table->text('stripe_response')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dt_payments_trasactions');
    }
};
