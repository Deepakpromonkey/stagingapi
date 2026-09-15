<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counters for the two things that make a request send more than once.
 *
 * Chasing is time-driven: an agency that has not answered gets asked again.
 * Re-routing is reply-driven: the answer that came back said to write to
 * someone else. Both have to stop, and they have to stop independently — a
 * request that was re-routed to a new agency deserves a fresh set of chases,
 * and a request that has been chased twice must not chase forever because a
 * re-route reset it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('chase_count')->default(0)->after('sent_at');
            $table->timestamp('last_chase_at')->nullable()->after('chase_count');
            $table->unsignedTinyInteger('reroute_count')->default(0)->after('last_chase_at');
        });
    }

    public function down(): void
    {
        Schema::table('coi_insurance_requests', function (Blueprint $table) {
            $table->dropColumn(['chase_count', 'last_chase_at', 'reroute_count']);
        });
    }
};
