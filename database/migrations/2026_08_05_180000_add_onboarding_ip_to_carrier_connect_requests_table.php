<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address the carrier actually onboarded from.
 *
 * `didit_registration_ip` already records where the ID check was taken, but
 * that step is skippable, so it is absent for exactly the carriers a broker is
 * most likely to want to place. This is captured the moment the invitation link
 * is opened, so it exists for every onboarding regardless of which steps were
 * completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->ipAddress('onboarding_ip')->nullable()->after('first_visit_at');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropColumn('onboarding_ip');
        });
    }
};
