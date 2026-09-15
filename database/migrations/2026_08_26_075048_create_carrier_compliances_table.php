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
    Schema::create('carrier_compliances', function (Blueprint $table) {
        $table->id();
        $table->string('dot_number')->unique(); 
        $table->boolean('phmsa')->default(false);
        $table->boolean('carb')->default(false);
        $table->boolean('smartway')->default(false);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_compliances');
    }
};
