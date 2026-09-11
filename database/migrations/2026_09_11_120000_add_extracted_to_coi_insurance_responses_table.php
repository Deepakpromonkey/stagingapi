<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structured reading of an agency's reply.
 *
 * The expiry date has its own column because the card sorts and compares on
 * it. Everything else the model finds — limits, exclusions, commodity
 * sub-limits, the holder it was made out to, the insurer behind it — is shape
 * that varies by reply, so it is kept as one document rather than as fifteen
 * columns that are null on most rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coi_insurance_responses', function (Blueprint $table) {
            $table->json('extracted')->nullable()->after('llm_response');
        });
    }

    public function down(): void
    {
        Schema::table('coi_insurance_responses', function (Blueprint $table) {
            $table->dropColumn('extracted');
        });
    }
};
