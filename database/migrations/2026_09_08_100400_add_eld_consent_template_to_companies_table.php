<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Terminal consent template a broker's carriers are shown.
 *
 * The ELD step names one broker on screen — "so {broker} can see your hours of
 * service" — so the Link page the carrier reads has to name the same one. A
 * single shared template would show every carrier generic wording while the
 * wizard around it made a specific promise, which is the wrong way round for a
 * record of consent to share driver location and duty status.
 *
 * Null falls back to the account-level default, which is the right behaviour
 * for a broker who has not had one created yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('eld_consent_template', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('eld_consent_template');
        });
    }
};
