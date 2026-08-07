<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('driver_locations', function (Blueprint $table) {
            $table->uuid('shipment_uuid')->nullable()->after('driver_id'); 
        });
    }

    public function down()
    {
        Schema::table('driver_locations', function (Blueprint $table) {
            $table->dropColumn('shipment_uuid');
        });
    }
};