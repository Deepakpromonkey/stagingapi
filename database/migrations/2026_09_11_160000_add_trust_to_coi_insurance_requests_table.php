<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the certificate can be believed at all.
 *
 * Separate from `verification`, which asks whether a genuine certificate
 * agrees with the federal filing. This asks the prior question: was this
 * document issued by the agency whose name is on it. A forged limit and a
 * filing lag are both "do not haul on this yet" and they are not the same
 * problem, so they are not the same column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->json('trust')->nullable()->after('coverage');
        });
    }

    public function down(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->dropColumn('trust');
        });
    }
};
