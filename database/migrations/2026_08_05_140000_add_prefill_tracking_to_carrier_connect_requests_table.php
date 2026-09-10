<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a request was seeded from a carrier's earlier onboarding with a
 * different broker.
 *
 * A carrier who has already verified their phone and ID, connected a bank and
 * uploaded their W-9 and COI should not be made to do it all again for the next
 * broker — only the parts that are actually broker-specific, the questionnaire
 * and the agreement, are theirs to redo.
 *
 * Kept as columns rather than inferred so support can see where a request's
 * verifications came from without reconstructing it from timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {

            $table->foreignId('prefilled_from_request_id')
                ->nullable()
                ->after('user_id')
                ->constrained('carrier_connect_requests')
                ->nullOnDelete();

            $table->timestamp('prefilled_at')->nullable()->after('prefilled_from_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropForeign(['prefilled_from_request_id']);
            $table->dropColumn(['prefilled_from_request_id', 'prefilled_at']);
        });
    }
};
