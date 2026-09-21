<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The terms of the cover, rolled up onto the request.
 *
 * The same facts are already on the reply that carried them, but a dispatcher
 * about to tender a load is asking about the carrier, not about a mail — and
 * asking that question through the thread would mean re-reading every reply
 * on every tender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->json('coverage')->nullable()->after('verification');
        });
    }

    public function down(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->dropColumn('coverage');
        });
    }
};
