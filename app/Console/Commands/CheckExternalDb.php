<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the EC2 carrier database is reachable and reports its schema, so
 * the search query can be matched to the columns that actually exist there.
 */
class CheckExternalDb extends Command
{
    protected $signature = 'carriers:check {--table=carriers : Table to describe}';

    protected $description = 'Test the EC2 carrier database connection and show its schema';

    public function handle(): int
    {
        $config = config('database.connections.external_db');

        if (empty($config['host']) || empty($config['database'])) {
            $this->error('EXTERNAL_DB_HOST / EXTERNAL_DB_DATABASE are not set in .env');

            return self::FAILURE;
        }

        $this->line("Connecting to {$config['username']}@{$config['host']}:{$config['port']}/{$config['database']} ...");

        try {
            $connection = DB::connection('external_db');

            $connection->select('select 1');

            $this->info('✓ Connected');
        } catch (\Throwable $e) {
            $this->error('✗ '.$e->getMessage());

            $this->newLine();
            $this->line('Common causes:');
            $this->line('  • EC2 security group does not allow port 3306 from your IP');
            $this->line('  • MySQL user is not granted access from a remote host');
            $this->line('  • MySQL bind-address is 127.0.0.1 on the EC2 box');

            return self::FAILURE;
        }

        $tables = $connection->select('show tables');

        $this->newLine();
        $this->line('Tables ('.count($tables).'):');

        foreach ($tables as $row) {
            $this->line('  - '.reset($row));
        }

        $table = $this->option('table');

        try {
            $columns = $connection->select("describe `{$table}`");

            $this->newLine();
            $this->line("Columns in `{$table}`:");

            $this->table(
                ['Field', 'Type', 'Null', 'Key'],
                array_map(fn ($c) => [$c->Field, $c->Type, $c->Null, $c->Key], $columns)
            );

            $count = $connection->table($table)->count();

            $this->info("Rows in `{$table}`: {$count}");
        } catch (\Throwable $e) {
            $this->warn("Could not describe `{$table}`: ".$e->getMessage());
        }

        return self::SUCCESS;
    }
}
