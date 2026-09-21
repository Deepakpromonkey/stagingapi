<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::connection('external_db')->create('zip_centroids', function (Blueprint $table) {
            $table->string('zip', 5)->primary();
            $table->string('city', 80);
            $table->string('state', 2);
            $table->double('lat');
            $table->double('lng');
            
            $table->index(['lat', 'lng']);
        });
    }

    public function down()
    {
        Schema::connection('external_db')->dropIfExists('zip_centroids');
    }
};