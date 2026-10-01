<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the certificate looked like next to the FMCSA filing.
 *
 * A certificate can be perfectly valid and still not clear a carrier: L&I may
 * still show the prior insurer, or carry a pending cancellation the agency has
 * already rescinded. The comparison is kept rather than recomputed so the card
 * can say why a carrier is being held without reaching across to the external
 * database on every render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->json('verification')->nullable()->after('insurance_expiry_date');
            $table->timestamp('verified_at')->nullable()->after('verification');
        });
    }

    public function down(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->dropColumn(['verification', 'verified_at']);
        });
    }
};
