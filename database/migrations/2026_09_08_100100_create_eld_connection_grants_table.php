<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which broker company a carrier agreed to share their fleet data with.
 *
 * The connection is shared; the consent is not. A carrier who stops working
 * with one broker revokes that broker's grant, and the connection keeps serving
 * the others rather than every relationship collapsing at once.
 *
 * This is also what makes the consent record match what the carrier was shown:
 * the ELD step names a single broker on screen, so the agreement being stored
 * has to name one too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eld_connection_grants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('eld_connection_id')
                ->constrained('eld_connections')
                ->cascadeOnDelete();

            // The broker company, matching how carrier_connect_requests are
            // scoped — a colleague of the sender sees the same grant.
            $table->foreignId('company_id')->constrained('companies');

            // The onboarding this consent was collected during. Nullable
            // because a grant may outlive the request that created it.
            $table->foreignId('carrier_connect_request_id')
                ->nullable()
                ->constrained('carrier_connect_requests')
                ->nullOnDelete();

            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            // The Link page the carrier actually read, so the consent record
            // says which wording and branding they were shown.
            $table->string('consent_template', 64)->nullable();

            $table->timestamps();

            $table->unique(
                ['eld_connection_id', 'company_id'],
                'eld_connection_grants_connection_company_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eld_connection_grants');
    }
};
