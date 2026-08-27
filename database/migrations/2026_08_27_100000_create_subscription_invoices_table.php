<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    | A local mirror of the Stripe invoices raised against a company.
    |
    | Stripe remains the source of truth — nothing here is ever written except
    | from a payload Stripe gave us, the same contract App\Models\Subscription
    | already keeps. The mirror exists so the billing report can be built with
    | one query instead of paging the Stripe API on every page load, so an
    | invoice stays readable after a plan or price change, and so "email me
    | this invoice" can be made idempotent.
    */
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            // Nullable: a one-off invoice, or one whose subscription row has
            // not been synced yet, still belongs in the history.
            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('stripe_invoice_id')->unique();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_charge_id')->nullable();

            // Stripe's human-facing invoice number (DT-0001-0001 style), which
            // is what the customer quotes at us. Absent until finalised.
            $table->string('number', 60)->nullable();

            // Stripe's own status, verbatim: draft, open, paid, void,
            // uncollectible.
            $table->string('status', 30)->default('draft');

            // Plan key at the time of billing, so a later plan rename or
            // upgrade does not rewrite history.
            $table->string('plan', 50)->nullable();

            $table->string('currency', 3)->default('usd');

            // Everything in the smallest currency unit, as Stripe reports it.
            $table->integer('subtotal_cents')->default(0);
            $table->integer('tax_cents')->default(0);
            $table->integer('discount_cents')->default(0);
            $table->integer('total_cents')->default(0);
            $table->integer('amount_paid_cents')->default(0);
            $table->integer('amount_due_cents')->default(0);

            // charge_automatically (a card on file) or send_invoice (billed to
            // terms). Decides whether Stripe can be asked to email it.
            $table->string('collection_method', 30)->nullable();

            $table->string('description')->nullable();

            // Stripe's own hosted copies. Kept so the customer can always
            // reach the provider's record alongside our branded PDF.
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf_url')->nullable();

            // The service period this invoice covers.
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // How the charge was settled, for the PDF's payment line.
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last4', 4)->nullable();

            $table->unsignedSmallInteger('attempt_count')->default(0);

            // The line items, as Stripe described them. Stored rather than
            // recomputed so the PDF reproduces the invoice that was actually
            // raised, prorations and credits included.
            $table->json('lines')->nullable();

            // Set when our own branded copy was emailed, so a webhook replay
            // does not send it twice.
            $table->timestamp('emailed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
