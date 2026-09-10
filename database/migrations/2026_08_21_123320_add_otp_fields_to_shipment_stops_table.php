<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_stops', function (Blueprint $table) {
            $table->boolean('requires_otp')->default(false)->after('events');
            $table->string('otp_code', 10)->nullable()->after('requires_otp');
            $table->timestamp('otp_verified_at')->nullable()->after('otp_code');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_stops', function (Blueprint $table) {
            $table->dropColumn(['requires_otp', 'otp_code', 'otp_verified_at']);
        });
    }
};