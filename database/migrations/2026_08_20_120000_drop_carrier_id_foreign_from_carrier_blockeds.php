<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Removes carrier_blockeds.carrier_id's foreign key.
|
| The original create migration declared `constrained('carriers')`, which
| MySQL resolved against the LOCAL newbrokerapi.carriers — an empty, unrelated
| table. The ids actually stored here come from the `external_db` connection,
| where `carriers` is a view over company_census_file in the separate `carrier`
| database, so every block attempt failed:
|
|   SQLSTATE[23000]: 1452 Cannot add or update a child row: a foreign key
|   constraint fails (`newbrokerapi`.`carrier_blockeds`, CONSTRAINT
|   `carrier_blockeds_carrier_id_foreign` ...)
|
| carrier_shortlists — the working equivalent — stores carrier_id as a plain
| unsignedBigInteger for exactly this reason. This brings the blocklist into
| line on databases where the bad key was already created; the create migration
| no longer adds it, so on a fresh database there is nothing here to do.
*/
return new class extends Migration
{
    private function hasForeignKey(string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'carrier_blockeds')
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    public function up(): void
    {
        if (! Schema::hasTable('carrier_blockeds')) {
            return;
        }

        if (! $this->hasForeignKey('carrier_blockeds_carrier_id_foreign')) {
            return;
        }

        Schema::table('carrier_blockeds', function (Blueprint $table) {
            $table->dropForeign('carrier_blockeds_carrier_id_foreign');
        });
    }

    /*
    | Intentionally not reinstated. Putting the key back would restore the bug,
    | and any rows written while it was gone reference ids that the local
    | carriers table does not have — so the re-add would fail outright.
    */
    public function down(): void
    {
        //
    }
};
