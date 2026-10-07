<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the signed copy of the broker agreement is stored.
 *
 * Signing used to keep only the signature image and its position, so the
 * agreement anyone downloaded afterwards was still the broker's unsigned
 * original. The original stays where it is; this points at the copy with the
 * signature stamped on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->string('signed_agreement_disk')->nullable()->after('signed_at');
            $table->string('signed_agreement_path')->nullable()->after('signed_agreement_disk');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropColumn(['signed_agreement_disk', 'signed_agreement_path']);
        });
    }
};
