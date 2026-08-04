<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();
            // Links the search log to the specific broker
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); 
            // The data we are saving from the external API response
            $table->unsignedBigInteger('carrier_id'); 
            $table->string('company_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_histories');
    }
};