<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::create('carrier_blockeds', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); 
        $table->timestamps();
        
        $table->unique(['company_id', 'carrier_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_blockeds');
    }
};
