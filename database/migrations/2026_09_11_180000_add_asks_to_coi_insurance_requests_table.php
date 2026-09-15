<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this particular request asked the agency for.
 *
 * Stored rather than assumed from the template, because the template will
 * change and a reply has to be readable against the questions it was actually
 * answering — an empty schedule means something very different when the
 * schedule was never asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->json('asks')->nullable()->after('subject');
            $table->string('holder_name')->nullable()->after('asks');
            $table->text('ask_note')->nullable()->after('holder_name');
        });
    }

    public function down(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->dropColumn(['asks', 'holder_name', 'ask_note']);
        });
    }
};
