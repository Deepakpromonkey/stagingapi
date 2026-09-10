<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            $table->string('tracking_number')->unique();
            
            $table->string('template_name')->nullable();
            
            $table->json('template_data');
            
            $table->timestamps();
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('shipment_templates');
    }
};
