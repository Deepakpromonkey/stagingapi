<?php

namespace App\Console\Commands;

use App\Exceptions\DrayageException;
use App\Services\Drayage\DrayageAudit;
use App\Services\Drayage\DrayageImporter;
use Illuminate\Console\Command;

/**
 * Makes a kept drayage dataset live - the rollback, from the shell. Same
 * effect as POST /drayage/datasets/{id}/activate: the pointer moves and
 * readers follow on their next request.
 *
 *   php artisan drayage:status                 # find the dataset id
 *   php artisan drayage:activate ds-20261002T182417123Z-ee4a4e
 */
class DrayageActivate extends Command
{
    protected $signature = 'drayage:activate {dataset : Dataset id, from drayage:status}';

    protected $description = 'Activate a kept drayage dataset (rollback or roll forward)';

    public function handle(DrayageImporter $importer): int
    {
        try {
            $pointer = $importer->activate($this->argument('dataset'), DrayageAudit::console('drayage:activate'), null, 'manual');
        } catch (DrayageException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Live: {$pointer['dataset_id']} (was ".($pointer['previous_dataset_id'] ?? 'none').').');

        return self::SUCCESS;
    }
}
