<?php

namespace Tests;

/**
 * Lets a test suite run the real migrations on sqlite.
 *
 * Two migrations issue raw `ALTER TABLE ... MODIFY`, which is MySQL syntax and
 * blows up on the in-memory sqlite connection the tests use — taking the whole
 * suite with it before a single assertion runs.
 *
 * Rather than fork the schema into the tests (which then rots), this mirrors
 * `database/migrations` into a scratch directory minus those files and points
 * RefreshDatabase at the copy. The tables they alter are unrelated to anything
 * under test; skipping them costs nothing here.
 */
trait PortableMigrations
{
    /**
     * Migrations whose bodies use MySQL-only SQL — `ALTER ... MODIFY`,
     * `SHOW INDEX`, and friends. Keep this in step with:
     *
     *   grep -rlE 'SHOW INDEX|SHOW COLUMNS|MODIFY|information_schema' database/migrations
     */
    protected array $mysqlOnlyMigrations = [
        '2026_07_15_075924_alter_otp_column_in_login_otps_table.php',
        '2026_07_29_100000_add_company_scope_to_scoring_weights_and_shortlists.php',
        '2026_08_15_135901_add_broker_id_foreign_key_to_dt_payments_table.php',
        '2026_08_20_120000_drop_carrier_id_foreign_from_carrier_blockeds.php',

        // Names its unique index `dt_payments_row_id_unique`, which the
        // dt_payments table already took. MySQL scopes index names per table,
        // sqlite scopes them per database.
        '2026_08_10_000003_create_dt_pay_guest_table.php',
    ];

    /**
     * RefreshDatabase hands these straight to `migrate:fresh`.
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => $this->portableMigrationPath(),
            '--realpath' => true,
            '--drop-views' => false,
            '--drop-types' => false,
        ];
    }

    protected function portableMigrationPath(): string
    {
        $source = database_path('migrations');
        $target = storage_path('framework/testing/portable-migrations');

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        // Rebuilt every run so a new or edited migration is never missed.
        foreach (glob($target.'/*.php') as $stale) {
            unlink($stale);
        }

        foreach (glob($source.'/*.php') as $migration) {
            if (in_array(basename($migration), $this->mysqlOnlyMigrations, true)) {
                continue;
            }

            copy($migration, $target.'/'.basename($migration));
        }

        return $target;
    }
}
