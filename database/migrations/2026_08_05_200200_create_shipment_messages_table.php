<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chat between a broker and the driver on one shipment.
 *
 * Scoped to a shipment rather than to a broker/driver pair on purpose: the
 * conversation is about a load, it needs to live alongside that load's record,
 * and the same driver may run for the same broker many times over.
 *
 * Sender is a polymorphic pair rather than two nullable foreign keys, because
 * "who sent this" has exactly one answer and nullable columns would let a row
 * exist with both set or neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('shipment_id')
                ->constrained('shipments')
                ->cascadeOnDelete();

            // 'broker' (a User) or 'driver' (a Driver).
            $table->string('sender_type', 10);
            $table->unsignedBigInteger('sender_id');

            // Denormalised so a deleted account does not turn every past
            // message into "unknown". The chat is a record of what was said.
            $table->string('sender_name')->nullable();

            $table->text('body');

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The only read this table really serves: one shipment's thread,
            // oldest first.
            $table->index(['shipment_id', 'id']);

            // Drives the broker's unread badge.
            $table->index(['shipment_id', 'sender_type', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_messages');
    }
};
