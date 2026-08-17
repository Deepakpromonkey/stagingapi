<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {

            // The Stripe customer the monthly subscription is billed to. Kept
            // on the company rather than the user so ownership can move
            // between seats without stranding the billing history.
            $table->string('stripe_customer_id')
                ->nullable()
                ->after('status');

            $table->index('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);

            $table->dropColumn('stripe_customer_id');
        });
    }
};
