<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone_country_code', 10)->nullable();
            $table->string('phone')->nullable();
            $table->string('job_title')->nullable();
            $table->string('company')->nullable();
            $table->string('country')->nullable();
            $table->string('business_type')->nullable();
            $table->json('features_of_interest')->nullable(); // JSON because it can be an array
            $table->string('hear_about_us')->nullable();
            $table->text('message')->nullable();
            $table->boolean('subscribe_updates')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('contact_requests');
    }
};