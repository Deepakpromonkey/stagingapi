<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| DT Pay, rebuilt. New tables only: the first version's dt_payments /
| dt_pay_* tables are left exactly as they are.
|
| Money is stored in cents (integers), never floats.
*/
return new class extends Migration
{
    public function up(): void
    {
        // A company's Stripe customer, per Stripe mode (test / live).
        Schema::create('dtpay_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->boolean('livemode');
            $table->string('stripe_customer_id', 64);
            $table->timestamps();

            $table->unique(['company_id', 'livemode']);
        });

        // A carrier's Stripe Express account for receiving payouts, per mode.
        Schema::create('dtpay_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('carrier_dot', 16);
            $table->boolean('livemode');
            $table->string('stripe_account_id', 64);
            $table->boolean('details_submitted')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('transfers_active')->default(false);
            $table->timestamps();

            $table->unique(['carrier_dot', 'livemode']);
        });

        Schema::create('dtpay_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 20)->unique();      // DTP-000123

            // Broker payments: the paying company and who started it.
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('created_by')->nullable();

            // shipment | manual | guest
            $table->string('source', 16);
            $table->foreignId('shipment_id')->nullable()->index();

            // Guest Pay: who paid, and the secret their tracking link carries.
            $table->string('guest_token', 64)->nullable()->unique();
            $table->string('payer_name')->nullable();
            $table->string('payer_company')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_phone', 32)->nullable();

            // Who gets paid.
            $table->string('carrier_dot', 16)->index();
            $table->string('carrier_name')->nullable();
            $table->string('carrier_mc', 32)->nullable();

            // The load.
            $table->string('load_number', 64)->nullable();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->date('pickup_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->text('memo')->nullable();

            // Money, in cents.
            $table->unsignedBigInteger('amount_cents');        // to the carrier, in full
            $table->unsignedBigInteger('platform_fee_cents')->default(0);
            $table->unsignedBigInteger('card_fee_cents')->default(0);
            $table->unsignedBigInteger('total_cents');         // charged to the payer
            $table->decimal('platform_fee_pct', 5, 2);
            $table->string('currency', 3)->default('usd');
            $table->boolean('livemode');

            // How it was paid.
            $table->string('method_type', 20)->nullable();     // card | us_bank_account
            $table->string('stripe_payment_method_id', 64)->nullable();
            $table->string('method_label')->nullable();
            $table->string('stripe_payment_intent_id', 64)->nullable()->unique();
            $table->string('stripe_charge_id', 64)->nullable();

            /*
            | draft -> processing -> funded -> released
            |                     \-> failed (pay again)
            | funded -> refunded;  draft -> cancelled
            | 'releasing' / 'refunding' are held only while the Stripe call runs,
            | so two clicks can never move the same money twice.
            */
            $table->string('status', 16)->default('draft')->index();
            $table->boolean('on_hold')->default(false);
            $table->string('failure_reason')->nullable();

            $table->string('rate_confirmation_path')->nullable();
            $table->string('pod_path')->nullable();

            $table->timestamp('funded_at')->nullable();
            $table->string('stripe_transfer_id', 64)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable();
            $table->string('stripe_refund_id', 64)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // The timeline shown on a payment.
        Schema::create('dtpay_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index();
            $table->string('type', 40);
            $table->string('label');
            $table->string('detail')->nullable();
            $table->string('actor_type', 16);                  // broker | guest | stripe | system
            $table->foreignId('actor_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('dtpay_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index();
            $table->string('raised_by_type', 16);              // broker | guest
            $table->foreignId('raised_by')->nullable();
            $table->string('reason', 40);
            $table->text('details');
            $table->json('evidence_paths')->nullable();
            $table->string('status', 16)->default('open');     // open | resolved
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable();
            $table->timestamps();
        });

        // Stripe events already handled, so a redelivered webhook is a no-op.
        Schema::create('dtpay_webhook_events', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->string('type', 80);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['dtpay_webhook_events', 'dtpay_disputes', 'dtpay_events', 'dtpay_payments', 'dtpay_payout_accounts', 'dtpay_customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
