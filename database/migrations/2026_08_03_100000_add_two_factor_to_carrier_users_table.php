<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_users', function (Blueprint $table) {

            // On by default, matching broker staff. A carrier signing in from a
            // device they have not trusted has to clear an emailed code first.
            $table->boolean('two_factor_enabled')
                ->default(true)
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_users', function (Blueprint $table) {
            $table->dropColumn('two_factor_enabled');
        });
    }
};
