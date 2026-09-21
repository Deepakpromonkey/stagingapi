<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * zip_centroids lives on external_db — the shared `carrier` database that
 * BOTH staging and production point at. The migrations table, however, is
 * per-environment on the default connection, so each environment runs this
 * migration once against the same physical table.
 *
 * Staging created it first. Production's deploy then hit
 * SQLSTATE[42S01] 1050 "Table 'zip_centroids' already exists", and because
 * the workflow is `artisan down` -> migrate -> `artisan up`, the failure
 * exited before `up` and left production serving 503 until someone lifted
 * maintenance mode by hand. The migration also never recorded, so every
 * subsequent deploy would have repeated the outage.
 *
 * Creating only when absent makes the migration safe to run in whichever
 * environment gets there second: it records itself and the deploy proceeds.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::connection('external_db')->hasTable('zip_centroids')) {
            return;
        }

        Schema::connection('external_db')->create('zip_centroids', function (Blueprint $table) {
            $table->string('zip', 5)->primary();
            $table->string('city', 80);
            $table->string('state', 2);
            $table->double('lat');
            $table->double('lng');

            $table->index(['lat', 'lng']);
        });
    }

    /*
     * Deliberately does NOT drop the table. It is shared, so a rollback in
     * one environment would take the data away from the other. Drop it by
     * hand if it genuinely needs to go.
     */
    public function down()
    {
        // no-op: shared table, see note above
    }
};
