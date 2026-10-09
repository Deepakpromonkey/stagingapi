<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The receipt for every DT score change: what the engine said, under which
| model and config, and the full payload it said it with. One row per
| distinct result, so re-scoring a carrier whose data has not moved adds
| nothing. Shipments point at the row that was current when they were
| booked, which freezes the score a broker actually saw.
|
| Both changes only add: a new table and a nullable column.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_score_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('dot_number', 16);
            $table->string('model_version', 32);
            $table->char('config_fingerprint', 10);
            $table->unsignedTinyInteger('score');
            $table->string('status', 32);
            $table->string('band', 24);
            $table->boolean('needs_manual_review')->default(false);
            $table->json('payload');
            $table->char('payload_fingerprint', 40);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['dot_number', 'payload_fingerprint'], 'uq_dot_fp');
            $table->index(['dot_number', 'created_at'], 'idx_dot_created');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('trust_score_evaluation_id')->nullable()->after('carrier_dot')
                ->constrained('trust_score_evaluations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trust_score_evaluation_id');
        });

        Schema::dropIfExists('trust_score_evaluations');
    }
};
