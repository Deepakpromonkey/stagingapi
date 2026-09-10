<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{ 
    public function up()
    {
        Schema::dropIfExists('carrier_shortlists');

        Schema::create('carrier_shortlists', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('carrier_id');
            
            $table->timestamps();

            $table->unique(['user_id', 'carrier_id']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('carrier_shortlists');
    }
};