<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Clear the older ELD scaffolding out of the way so the App\Models\Eld
 * implementation can create its own.
 *
 * Production briefly carried a first pass at ELD (App\Models\EldConnection,
 * config/terminal.php) whose migrations ran on 2026-09-21. The staging
 * lineage grew a separate, fuller implementation under App\Models\Eld with
 * its own tables, and that is the one being adopted. The two describe the
 * same tables with different columns -- eld_connections had provider_code /
 * account_name / dot_numbers / payload, the new one has provider /
 * carrier_dot_number / vehicle_count / archived_at -- so the incoming
 * create_* migrations would fail with SQLSTATE[42S01] 1050 against tables
 * that are already there. That kind of failure mid-deploy is what left
 * production serving 503 earlier today, so it is cleared up front.
 *
 * NOTHING IS DROPPED THAT HOLDS ANYTHING. Every table drop is guarded on the
 * table being empty, and every column drop on the column being entirely
 * null. Checked against production before writing this: all five tables at
 * 0 rows, and all four carrier_connect_requests columns null across its 67
 * rows -- the feature had been deployed hours earlier and never used. If any
 * of that is no longer true when this runs, the guard skips that drop and
 * leaves the data alone.
 */
return new class extends Migration
{
    private const LEGACY_TABLES = [
        'eld_vehicle_locations',
        'eld_hos_logs',
        'eld_drivers',
        'eld_vehicles',
        'eld_connections',
    ];

    private const LEGACY_COLUMNS = [
        'eld_connection_id',
        'eld_link_state',
        'eld_connected_at',
        'eld_skipped_at',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::table($table)->count() > 0) {
                continue;   // holds data: leave it, and let the create fail loudly
            }

            Schema::drop($table);
        }

        if (! Schema::hasTable('carrier_connect_requests')) {
            return;
        }

        $drop = [];

        foreach (self::LEGACY_COLUMNS as $column) {
            if (! Schema::hasColumn('carrier_connect_requests', $column)) {
                continue;
            }

            if (DB::table('carrier_connect_requests')->whereNotNull($column)->exists()) {
                continue;   // someone has used it: leave it alone
            }

            $drop[] = $column;
        }

        if ($drop !== []) {
            Schema::table('carrier_connect_requests', function ($table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }

    /*
     * Not reversible, and deliberately so: the tables this removes are
     * recreated by the migrations that follow it, in the shape the current
     * code expects. Putting the old shape back would only collide again.
     */
    public function down(): void
    {
        // no-op
    }
};
