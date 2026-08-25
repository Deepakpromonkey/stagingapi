<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vPIC's descriptive fields are longer than 100 characters for some vehicles —
 * body_class and model both overflowed on the production backfill:
 *
 *   SQLSTATE[22001]: Data too long for column 'body_class' at row 35
 *
 * That matters more than a truncated label. Patterns are written one batch at
 * a time as a single upsert, so a single oversized value aborts the whole
 * statement and loses all fifty rows with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vin_patterns', function (Blueprint $table) {
            $table->string('make', 255)->nullable()->change();
            $table->string('model', 255)->nullable()->change();
            $table->string('vehicle_type', 255)->nullable()->change();
            $table->string('body_class', 255)->nullable()->change();
            $table->string('gvwr', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vin_patterns', function (Blueprint $table) {
            $table->string('make', 100)->nullable()->change();
            $table->string('model', 100)->nullable()->change();
            $table->string('vehicle_type', 50)->nullable()->change();
            $table->string('body_class', 100)->nullable()->change();
            $table->string('gvwr', 100)->nullable()->change();
        });
    }
};
