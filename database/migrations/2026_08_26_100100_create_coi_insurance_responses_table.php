<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agency's reply, kept whole.
 *
 * The extracted date on the request row is a claim about an insurance policy
 * that a broker may act on, so the mail it was read out of is retained rather
 * than discarded — that is what the "view response" link on the card opens,
 * and what makes a wrong extraction auditable instead of unexplainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coi_insurance_responses', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('coi_insurance_request_id')
                ->constrained('coi_insurance_requests')
                ->cascadeOnDelete();

            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();

            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();

            // The provider's payload as it arrived. Providers differ, and the
            // one thing that reliably explains a mis-routed reply is what was
            // actually posted.
            $table->json('raw_payload')->nullable();

            // What Claude answered, verbatim, before it was parsed into a date.
            $table->text('llm_response')->nullable();

            $table->date('extracted_expiry_date')->nullable();

            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->index('coi_insurance_request_id', 'coi_responses_request_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coi_insurance_responses');
    }
};
