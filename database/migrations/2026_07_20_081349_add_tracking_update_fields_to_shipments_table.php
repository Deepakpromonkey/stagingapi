<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dateTime('update_datetime')->nullable()->after('tracking_start_at');
            $table->string('tracking_days')->nullable()->after('update_datetime');
            $table->string('tracking_interval')->nullable()->after('tracking_days');
            $table->string('email_updates_to')->nullable()->after('tracking_interval');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'update_datetime',
                'tracking_days',
                'tracking_interval',
                'email_updates_to'
            ]);
        });
    }
};