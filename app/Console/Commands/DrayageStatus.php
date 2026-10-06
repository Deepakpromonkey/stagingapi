<?php

namespace App\Console\Commands;

use App\Services\Drayage\DrayageStorage;
use Illuminate\Console\Command;

/**
 * The live drayage dataset, the versions kept for rollback, and the latest
 * imports - what to look at before and after an import or a rollback.
 *
 *   php artisan drayage:status
 */
class DrayageStatus extends Command
{
    protected $signature = 'drayage:status';

    protected $description = 'Show the live drayage dataset, kept versions and recent imports';

    public function handle(DrayageStorage $storage): int
    {
        $current = $storage->current();

        $this->components->twoColumnDetail('Store', $storage->root());
        $this->components->twoColumnDetail('Live dataset', $current['dataset_id'] ?? 'none');
        if ($current) {
            $this->components->twoColumnDetail('Activated', $current['activated_at']);
        }

        $this->newLine();
        $this->table(
            ['Dataset', 'Live', 'Carriers', 'Full', 'Listings', 'Source file', 'Created', 'MB'],
            array_map(fn ($m) => [
                $m['dataset_id'],
                $m['dataset_id'] === ($current['dataset_id'] ?? null) ? 'yes' : '',
                $m['counts']['total'] ?? '',
                $m['counts']['full_profiles'] ?? '',
                $m['counts']['listings'] ?? '',
                $m['source_filename'] ?? '',
                $m['created_at'] ?? '',
                round($storage->datasetBytes($m['dataset_id']) / 1048576, 1),
            ], $storage->datasets())
        );

        $this->table(
            ['Import', 'Status', 'Read', 'Imported', 'Rejected', 'Dataset', 'Error'],
            array_map(fn ($i) => [
                $i['import_id'],
                $i['status'],
                $i['report']['rows']['read'] ?? '',
                $i['report']['rows']['imported'] ?? '',
                $i['report']['rows']['rejected'] ?? '',
                $i['dataset_id'] ?? '',
                mb_strimwidth((string) ($i['error'] ?? ''), 0, 60, '…'),
            ], array_slice($storage->imports(), 0, 10))
        );

        return self::SUCCESS;
    }
}
