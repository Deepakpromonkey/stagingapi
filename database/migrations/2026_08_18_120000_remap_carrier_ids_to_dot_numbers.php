<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Re-key the local carrier references onto DOT numbers.
 *
 * `carrier_shortlists.carrier_id` and `search_histories.carrier_id` used to
 * hold `dollar_traq.carriers.id` — a surrogate key from the old carrier
 * database. The application now reads the `carrier` database, where the
 * identity is the DOT number, so those stored ids point at nothing.
 *
 * The old table is still on the same server, so the mapping is recoverable.
 * Rows whose id is no longer in the old table are left alone and logged rather
 * than deleted: a shortlist entry is a user's own data.
 *
 * Safe to re-run — a second pass finds no rows left to map.
 */
return new class extends Migration
{
    /** Where the old id -> dot_number mapping still lives. */
    private const LEGACY_TABLE = 'dollar_traq.carriers';

    private const TABLES = ['carrier_shortlists', 'search_histories'];

    public function up(): void
    {
        $map = $this->legacyMap();

        if ($map === null) {
            Log::warning('Carrier id remap skipped: legacy carrier table unreachable.');

            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $remapped = 0;
            $orphans = [];

            foreach (DB::table($table)->select('id', 'carrier_id')->get() as $row) {
                $dot = $map[$row->carrier_id] ?? null;

                if ($dot === null) {
                    $orphans[] = $row->carrier_id;

                    continue;
                }

                if ((int) $dot !== (int) $row->carrier_id) {
                    DB::table($table)->where('id', $row->id)->update(['carrier_id' => $dot]);
                    $remapped++;
                }
            }

            Log::info("Carrier id remap: {$table}", [
                'remapped' => $remapped,
                'unmapped' => count($orphans),
                'unmapped_ids' => array_slice(array_unique($orphans), 0, 50),
            ]);
        }
    }

    /**
     * The remap is not reversible: once a row holds a DOT number there is no
     * way to tell it apart from an old surrogate id that happened to match.
     */
    public function down(): void {}

    /**
     * old carriers.id => dot_number, read across the connection the carrier
     * database lives on. Returns null if the legacy table has been dropped.
     */
    private function legacyMap(): ?array
    {
        try {
            return DB::connection('external_db')
                ->table(DB::raw(self::LEGACY_TABLE))
                ->select('id', 'dot_number')
                ->pluck('dot_number', 'id')
                ->all();
        } catch (Throwable $e) {
            Log::warning('Carrier id remap: legacy map unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
};
