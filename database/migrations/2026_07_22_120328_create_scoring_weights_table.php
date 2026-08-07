<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('scoring_weights', function (Blueprint $table) {
            $table->id();

            // Brokers are tied to the users table
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Template Flags
            $table->boolean('is_template')->default(false);
            $table->string('template_name')->nullable();

            // The Weights
            $table->integer('authority')->default(20);
            $table->integer('insurance_coi')->default(20);
            $table->integer('safety_csa')->default(20);
            $table->integer('inspection_vin')->default(15);
            $table->integer('fraud_signals')->default(10);
            $table->integer('payment_history')->default(15);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('scoring_weights');
    }
};
