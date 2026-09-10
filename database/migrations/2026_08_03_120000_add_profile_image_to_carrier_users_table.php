<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one thing on the portal's profile screen a carrier may edit.
     *
     * Everything else on that screen is either FMCSA data or the record the
     * broker onboarded them against, so it is read-only there by design.
     */
    public function up(): void
    {
        Schema::table('carrier_users', function (Blueprint $table) {
            $table->string('profile_image')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_users', function (Blueprint $table) {
            $table->dropColumn('profile_image');
        });
    }
};
