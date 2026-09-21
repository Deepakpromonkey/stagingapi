<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Terminal webhook we have already acted on.
 *
 * Terminal retries anything that does not answer 2xx, so the same event will
 * arrive twice sooner or later — a timeout on our side is enough. The unique
 * event id is what makes handling idempotent: the second delivery finds the row
 * already there, answers 2xx and does nothing.
 *
 * It doubles as the audit trail for a connection that broke overnight, which is
 * otherwise only visible in the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eld_webhook_events', function (Blueprint $table) {
            $table->id();

            // Terminal's `evt_...` id.
            $table->string('event_id', 64)->unique();

            $table->string('type', 60)->index();
            $table->string('terminal_connection_id', 64)->nullable()->index();

            $table->json('payload')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eld_webhook_events');
    }
};
