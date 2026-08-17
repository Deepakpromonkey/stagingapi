<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dt_pay_broker_stats', function (Blueprint $table) {
            $table->id();
            
            // Financial Summary Fields
            $table->decimal('hold_sum', 15, 2)->default(0.00);
            $table->unsignedInteger('hold_count')->default(0);

            $table->decimal('ready_to_release_sum', 15, 2)->default(0.00);
            $table->unsignedInteger('ready_to_release_count')->default(0);

            $table->decimal('released_sum', 15, 2)->default(0.00);
            $table->unsignedInteger('released_count')->default(0);

            $table->unsignedInteger('disputes_count')->default(0);

            // Last Updated Timestamp
            $table->timestamp('last_updated')->nullable();

            $table->timestamps();

            $table->foreignUuid('broker_id')
                  ->constrained('users', 'uuid')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_payment_summaries');
    }
};