<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_connect_requests', function (Blueprint $table) {

            $table->id();

            $table->uuid('uuid')->unique();

            // Onboarding belongs to the broker COMPANY, not to the individual
            // broker user, so any teammate can pick a request up where a
            // colleague left it.
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The carrier lives in the external (EC2) database, so we keep its
            // business keys plus a snapshot of the contact details we mailed.
            $table->string('carrier_row_id', 50);
            $table->string('carrier_dot_number', 50)->nullable();
            $table->string('carrier_legal_name')->nullable();
            $table->string('carrier_email')->nullable();
            $table->string('carrier_phone', 30)->nullable();

            // Unguessable handle the carrier-facing endpoints are keyed by.
            $table->string('token', 64)->unique();

            $table->string('status', 30)->default('new');

            $table->timestamp('sent_on')->nullable();
            $table->timestamp('first_visit_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mobile_verified_at')->nullable();

            $table->string('otp', 6)->nullable();
            $table->timestamp('otp_sent_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('last_otp_attempt_at')->nullable();

            $table->string('didit_session_id')->nullable();
            $table->string('didit_status', 30)->nullable();
            $table->timestamp('didit_responded_at')->nullable();
            $table->json('didit_response')->nullable();

            $table->string('stripe_express_account')->nullable();
            $table->timestamp('stripe_verified_at')->nullable();

            $table->foreignId('agreement_document_id')
                ->nullable()
                ->constrained('broker_agreement_documents')
                ->nullOnDelete();

            $table->string('signature_disk', 30)->nullable();
            $table->string('signature_path')->nullable();
            $table->unsignedSmallInteger('signature_page')->nullable();
            $table->decimal('signature_x_pct', 6, 3)->nullable();
            $table->decimal('signature_y_pct', 6, 3)->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();

            // One live onboarding per carrier per broker company.
            $table->unique(['company_id', 'carrier_row_id']);

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_connect_requests');
    }
};
