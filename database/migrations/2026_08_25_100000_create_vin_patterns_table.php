<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vin_patterns', function (Blueprint $table) {
            /*
            | Positions 1-8 and 10 of a VIN — see App\Support\Vin. This is the
            | primary key rather than an auto-increment id: every lookup is by
            | pattern, and the whole point of the table is that the key set is
            | small and stable.
            */
            $table->char('pattern', 9)->primary();

            $table->smallInteger('model_year')->nullable();
            $table->string('make', 100)->nullable();
            $table->string('model', 100)->nullable();

            // vPIC's own classification. Preferred over the FMCSA feed's
            // free-text unit_type_desc, which is inconsistently written.
            $table->string('vehicle_type', 50)->nullable();
            $table->string('body_class', 100)->nullable();
            $table->string('gvwr', 100)->nullable();

            // Derived once from vehicle_type/body_class so the fleet-age
            // aggregate does not have to pattern-match text on every run.
            $table->boolean('is_trailer')->default(false);

            /*
            | 'pending'  queued, not yet attempted
            | 'ok'       decoded, values above are usable
            | 'failed'   vPIC returned nothing usable; stop retrying
            |
            | Failures are recorded rather than left absent so a junk pattern
            | is not re-queued by every carrier profile that contains it.
            */
            $table->enum('status', ['pending', 'ok', 'failed'])->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('decoded_at')->nullable();

            $table->timestamps();

            // The fleet-age aggregate reads year + trailer flag for a set of
            // patterns; this lets it stay in the index.
            $table->index(['status', 'is_trailer', 'model_year'], 'vin_patterns_stats_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vin_patterns');
    }
};
