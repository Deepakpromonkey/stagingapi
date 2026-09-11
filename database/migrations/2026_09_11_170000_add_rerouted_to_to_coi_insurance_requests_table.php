<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address a reply told us to write to.
 *
 * Kept separately from `recipient_email` because on a staging run those two
 * differ: the mail goes to the test recipient, and this records where it
 * would have gone. Without it a staging run would report that every re-route
 * resolved perfectly, which is the one thing it is being run to check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->string('rerouted_to')->nullable()->after('reroute_count');
        });
    }

    public function down(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->dropColumn('rerouted_to');
        });
    }
};
