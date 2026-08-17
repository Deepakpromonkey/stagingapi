<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {

            // Loads are counted per billing period, so the window has to be
            // known — not just when it ends.
            $table->timestamp('current_period_starts_at')
                ->nullable()
                ->after('trial_ends_at');

            // Per-subscription override of the plan's monthly load allowance.
            // Null means "whatever the plan says", which is how every
            // self-serve subscription runs; it exists so an Enterprise deal
            // can be given the number it was actually sold.
            $table->unsignedInteger('load_limit')
                ->nullable()
                ->after('interval');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['current_period_starts_at', 'load_limit']);
        });
    }
};
