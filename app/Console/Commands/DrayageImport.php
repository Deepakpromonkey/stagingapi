<?php

namespace App\Console\Commands;

use App\Exceptions\DrayageException;
use App\Jobs\ImportDrayageDataset;
use App\Services\Drayage\DrayageAudit;
use App\Services\Drayage\DrayageImporter;
use Illuminate\Console\Command;

/**
 * Imports a drayage file that is already on the box.
 *
 * The upload endpoint is limited by nginx and php.ini before it ever reaches
 * the API; this is the way round both, and the way to do the first import.
 * Runs inline by default, under the same lock and through the same pipeline
 * as an upload, and prints the report.
 *
 *   php artisan drayage:import /home/ubuntu/drayage_carriers.csv
 *   php artisan drayage:import scrape.jsonl --format=jsonl
 *   php artisan drayage:import carriers.csv --queue
 */
class DrayageImport extends Command
{
    protected $signature = 'drayage:import
        {file : Path to the CSV, JSON or JSONL file}
        {--format= : csv, json or jsonl (default: from the extension)}
        {--queue : Queue the import for the worker instead of running it now}';

    protected $description = 'Import a drayage directory file and activate it';

    public function handle(DrayageImporter $importer): int
    {
        $path = $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $format = strtolower($this->option('format') ?: pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($format, DrayageImporter::FORMATS, true)) {
            $this->components->error('Format must be one of: '.implode(', ', DrayageImporter::FORMATS).'.');

            return self::FAILURE;
        }

        $import = $importer->queue($path, basename($path), $format, DrayageAudit::console('drayage:import'), null);

        if ($this->option('queue')) {
            ImportDrayageDataset::dispatch($import['import_id']);
            $this->components->info("Queued {$import['import_id']}.");

            return self::SUCCESS;
        }

        try {
            $import = $importer->run($import['import_id']);
        } catch (DrayageException $e) {
            $this->components->error($e->getMessage()." The import {$import['import_id']} stays queued.");

            return self::FAILURE;
        }

        $report = $import['report'] ?? [];

        $this->components->twoColumnDetail('Import', $import['import_id']);
        $this->components->twoColumnDetail('Status', $import['status']);
        if ($import['error']) {
            $this->components->twoColumnDetail('Error', $import['error']);
        }
        foreach ($report['rows'] ?? [] as $label => $count) {
            $this->components->twoColumnDetail('Rows '.$label, (string) $count);
        }
        $this->components->twoColumnDetail('Warnings', (string) ($report['warnings']['count'] ?? 0));
        $this->components->twoColumnDetail('Unknown columns', implode(', ', $report['headers']['unknown'] ?? []) ?: 'none');
        $this->components->twoColumnDetail('Missing columns', (string) count($report['headers']['missing'] ?? []));
        $this->components->twoColumnDetail('Dataset', $import['dataset_id'] ?? '-');
        $this->components->twoColumnDetail('Duration', ($import['duration_ms'] ?? 0).' ms');

        return $import['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
