<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Brings dt_payments.broker_id up to a real foreign key on users.uuid.
|
| This is a fix-up for databases created by the ORIGINAL create_dt_payments
| migration, where broker_id was a plain string carrying only an index. That
| migration has since been changed to `foreignUuid()->constrained()`, which
| creates the column, the key and the key's name — 'dt_payments_broker_id_foreign'
| — up front, so on any database built from scratch there is nothing left to do
| here and this migration must stand aside rather than add the key a second
| time (MySQL error 1826).
|
| The steps are checked individually because MySQL does not roll DDL back: an
| earlier failure part-way through leaves the completed statements in place
| while the migration stays unrecorded, so a re-run has to tolerate a database
| that is already half-way there.
*/
return new class extends Migration
{
    private function hasIndex(string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'dt_payments')
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function hasForeignKey(string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'dt_payments')
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    public function up(): void
    {
        // Already in place — either from a fresh create_dt_payments_table, or
        // from a previous partial run of this file. Leave the working key
        // alone; dropping and re-adding it would risk the table ending up
        // with no constraint at all if the re-add failed.
        if ($this->hasForeignKey('dt_payments_broker_id_foreign')) {
            return;
        }

        // The standalone index only exists on the older schema, and MySQL will
        // not drop it while a key depends on it — which is why this runs before
        // the key is created rather than after.
        if ($this->hasIndex('dt_payments_broker_id_index')) {
            Schema::table('dt_payments', function (Blueprint $table) {
                $table->dropIndex('dt_payments_broker_id_index');
            });
        }

        // Must match users.uuid exactly or the key cannot be created.
        DB::statement('ALTER TABLE dt_payments MODIFY broker_id CHAR(36) NOT NULL');

        Schema::table('dt_payments', function (Blueprint $table) {
            $table->foreign('broker_id', 'dt_payments_broker_id_foreign')
                  ->references('uuid')->on('users')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if ($this->hasForeignKey('dt_payments_broker_id_foreign')) {
            Schema::table('dt_payments', function (Blueprint $table) {
                $table->dropForeign('dt_payments_broker_id_foreign');
            });
        }

        DB::statement('ALTER TABLE dt_payments MODIFY broker_id VARCHAR(50) NOT NULL');

        if (! $this->hasIndex('dt_payments_broker_id_index')) {
            Schema::table('dt_payments', function (Blueprint $table) {
                $table->index('broker_id', 'dt_payments_broker_id_index');
            });
        }
    }
};
