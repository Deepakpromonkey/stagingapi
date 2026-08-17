<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            // Plan key from config('subscriptions.plans').
            $table->string('plan', 50);

            // Stripe's own status, stored verbatim: incomplete, incomplete_expired,
            // trialing, active, past_due, canceled, unpaid, paused.
            $table->string('status', 30)->default('incomplete');

            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable();

            // Set when the Checkout session is opened, so a return from Stripe
            // can be reconciled before the webhook lands.
            $table->string('stripe_checkout_session_id')->nullable();

            // Snapshot of what was charged, in the smallest currency unit, so
            // an old subscription still reads correctly after a price change.
            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('currency', 3)->default('usd');
            $table->string('interval', 20)->default('month');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();

            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
