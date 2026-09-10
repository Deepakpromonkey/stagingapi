<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dialling country behind `phone`.
 *
 * The profile screen and the invite form both ask for it, and the API already
 * accepted it on /update-profile — but there was nowhere to put it, so
 * User::fill() dropped it silently and every read came back null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ISO 3166-1 alpha-2 ("US", "IN", ...), not the dial code: the
            // dial code is not unique (US and CA are both +1), so storing it
            // would lose which country was actually picked.
            $table->string('country_code', 5)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
