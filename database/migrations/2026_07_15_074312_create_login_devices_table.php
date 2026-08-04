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
        Schema::create('login_devices', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('device_name')->nullable();

            $table->string('browser')->nullable();

            $table->string('platform')->nullable();

            $table->ipAddress('ip_address')->nullable();

            $table->string('user_agent')->nullable();

            $table->boolean('is_current')->default(false);

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_devices');
    }
};
