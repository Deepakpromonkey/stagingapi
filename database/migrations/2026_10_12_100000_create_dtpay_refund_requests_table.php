<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A broker asking DollarTraq to refund a held payment. Brokers cannot
| refund on their own: a DollarTraq admin approves (Stripe refund) or
| rejects each request from the admin panel.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtpay_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_id')->index();
            $table->foreignId('company_id')->index();
            $table->foreignId('requested_by')->nullable();     // broker user
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending')->index();   // pending | approving | approved | rejected | withdrawn
            $table->string('decided_by_name')->nullable();     // DollarTraq admin
            $table->string('decided_by_email')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtpay_refund_requests');
    }
};
