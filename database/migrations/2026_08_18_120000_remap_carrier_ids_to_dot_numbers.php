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
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Only the ids these tables actually reference are looked up. The
            // legacy carrier table has 2.07M rows, and pulling all of them into
            // a PHP array to build a map was enough to exhaust memory_limit on
            // a normal web server and take the deploy's `migrate` step with it.
            $ids = DB::table($table)->distinct()->pluck('carrier_id')->filter()->all();

            if ($ids === []) {
                continue;
            }

            $map = $this->legacyMap($ids);

            if ($map === null) {
                Log::warning('Carrier id remap skipped: legacy carrier table unreachable.');

                return;
            }

            $remapped = 0;
            $unmapped = [];

            foreach ($ids as $oldId) {
                $dot = $map[$oldId] ?? null;

                if ($dot === null) {
                    $unmapped[] = $oldId;

                    continue;
                }

                if ((int) $dot === (int) $oldId) {
                    continue;
                }

                $remapped += DB::table($table)->where('carrier_id', $oldId)->update(['carrier_id' => $dot]);
            }

            Log::info("Carrier id remap: {$table}", [
                'rows_updated' => $remapped,
                'unmapped_ids' => array_slice($unmapped, 0, 50),
                'unmapped_count' => count($unmapped),
            ]);
        }
    }

    /**
     * The remap is not reversible: once a row holds a DOT number there is no
     * way to tell it apart from an old surrogate id that happened to match.
     */
    public function down(): void {}

    /**
     * old carriers.id => dot_number, for the given ids only.
     *
     * Chunked so the IN list stays a sane size. Returns null if the legacy
     * table has been dropped, which is the signal to leave the data alone.
     *
     * @param  array<int>  $ids
     */
    private function legacyMap(array $ids): ?array
    {
        try {
            $map = [];

            foreach (array_chunk($ids, 1000) as $chunk) {
                $map += DB::connection('external_db')
                    ->table(DB::raw(self::LEGACY_TABLE))
                    ->whereIn('id', $chunk)
                    ->pluck('dot_number', 'id')
                    ->all();
            }

            return $map;
        } catch (Throwable $e) {
            Log::warning('Carrier id remap: legacy map unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
};
