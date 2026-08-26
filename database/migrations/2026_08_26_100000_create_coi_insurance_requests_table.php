<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "chase this carrier's agent for an up-to-date COI".
 *
 * Deliberately in the application database and not in `external_db`: the
 * carrier database is treated as read-only and carries no migrations, and this
 * is broker workflow state rather than FMCSA data. The link to the carrier is
 * the DOT number, which is how every other local table references one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coi_insurance_requests', function (Blueprint $table) {
            $table->id();

            // What the front end addresses a request by. The auto-increment id
            // is never exposed, so a broker cannot walk another company's rows.
            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('dot_number');
            $table->string('carrier_name')->nullable();
            $table->string('carrier_mc')->nullable();

            // Where it went, and where that address came from — `ocr` when the
            // COI extraction supplied it, `fmcsa` when it fell back to the
            // census record, `manual` when the broker typed one in.
            $table->string('recipient_email');
            $table->string('recipient_source', 16)->default('ocr');

            /*
             | pending   — mail sent, waiting on the agency
             | responded — a reply arrived; the date has not been read out yet
             | success   — an expiry date was extracted
             | failed    — the send failed, or the reply carried no usable date
             | expired   — nobody answered inside the configured window
             */
            $table->string('status', 16)->default('pending');

            // The random half of the Reply-To sub-address. Unique, because it
            // is what the inbound webhook matches a reply back to a row on.
            $table->string('reply_token', 32)->unique();

            $table->string('subject');

            // Message-ID of the outbound mail, kept so a reply that arrives
            // with In-Reply-To but a rewritten Reply-To can still be threaded.
            $table->string('message_id')->nullable();

            $table->date('insurance_expiry_date')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->text('last_error')->nullable();

            $table->timestamps();

            /*
             | The profile page asks one question — "is there an open request
             | for this DOT, for my company?" — on every render. Company first
             | because it is the more selective of the two once a broker has
             | chased a few hundred carriers.
             */
            $table->index(['company_id', 'dot_number', 'status']);

            $table->index(['status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coi_insurance_requests');
    }
};
