<?php

namespace App\Services\Drayage;

use App\Events\Drayage\DrayageDatasetActivated;
use App\Events\Drayage\DrayageImportFailed;
use App\Exceptions\DrayageException;
use App\Services\Drayage\Parsers\CsvSourceParser;
use App\Services\Drayage\Parsers\JsonSourceParser;
use App\Services\Drayage\Parsers\SourceParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Imports a drayage file into a new dataset version and makes it live; also
 * activates and deletes dataset versions on an administrator's say-so.
 *
 * An import either ends with its dataset live, or with the previous one
 * still live and a report saying why not. The new dataset is written beside
 * the old ones and only the final pointer swap changes what readers see, so
 * nothing in between - a crash, a rejected file, a full disk - can leave the
 * directory half-replaced.
 *
 * One import at a time, under a cache lock: two running at once would each
 * build a dataset and race to activate.
 */
class DrayageImporter
{
    public const FORMATS = ['csv', 'json', 'jsonl'];

    private const LOCK = 'drayage:import';

    public function __construct(
        private DrayageStorage $storage,
        private DrayageAudit $audit,
    ) {}

    /**
     * Records a file as a queued import. The file is copied into the store
     * first, so the job works from the store's copy, not from PHP's upload
     * temp file.
     */
    public function queue(string $sourcePath, string $originalName, string $format, ?array $actor, ?string $ip): array
    {
        $importId = DrayageStorage::newImportId();

        $import = [
            'import_id' => $importId,
            'status' => 'queued',
            'format' => $format,
            'source_filename' => mb_substr(basename($originalName), 0, 200),
            'source_sha256' => hash_file('sha256', $sourcePath),
            'source_bytes' => filesize($sourcePath),
            'source_storage_path' => $this->storage->storeOriginal($sourcePath, $importId, $format),
            'created_at' => now()->toIso8601String(),
            'created_by' => $actor,
            'ip' => $ip,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'dataset_id' => null,
            'activated' => false,
            'error' => null,
            'report' => null,
        ];

        $this->storage->saveImport($import);

        $this->audit->record('import.queued', $actor, $ip, [
            'import_id' => $importId,
            'source_filename' => $import['source_filename'],
            'source_sha256' => $import['source_sha256'],
        ]);

        return $import;
    }

    /**
     * Runs a queued import to completion. Returns the finished import record
     * (completed or failed); throws only when another import holds the lock,
     * in which case this one is left queued for a retry.
     */
    public function run(string $importId): array
    {
        $lock = DrayageStorage::lockStore()->lock(self::LOCK, config('drayage.import.timeout') + 60);

        if (! $lock->get()) {
            throw DrayageException::importRunning();
        }

        try {
            $this->raiseMemoryLimit();

            return $this->process($importId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Makes a complete dataset live. Used by imports and by rollback.
     */
    public function activate(string $datasetId, ?array $actor, ?string $ip, string $reason): array
    {
        if (! $this->storage->datasetExists($datasetId)) {
            throw new DrayageException('That dataset does not exist.', 404);
        }

        $pointer = $this->storage->activate($datasetId, $actor);

        event(new DrayageDatasetActivated($datasetId, $pointer['previous_dataset_id'], $actor, $reason));

        $this->audit->record('dataset.activated', $actor, $ip, [
            'dataset_id' => $datasetId,
            'previous_dataset_id' => $pointer['previous_dataset_id'],
            'reason' => $reason,
            'row_count' => $this->storage->manifest($datasetId)['counts']['total'] ?? null,
        ]);

        return $pointer;
    }

    public function delete(string $datasetId, ?array $actor, ?string $ip): void
    {
        if (! $this->storage->datasetExists($datasetId)) {
            throw new DrayageException('That dataset does not exist.', 404);
        }

        if ($this->storage->currentDatasetId() === $datasetId) {
            throw new DrayageException('The live dataset cannot be deleted. Activate another one first.', 409);
        }

        $rows = $this->storage->manifest($datasetId)['counts']['total'] ?? null;

        $this->storage->deleteDataset($datasetId);
        $this->forgetIndexCache($datasetId);

        $this->audit->record('dataset.deleted', $actor, $ip, ['dataset_id' => $datasetId, 'row_count' => $rows]);
    }

    public function parserFor(string $format): SourceParser
    {
        return match ($format) {
            'csv' => new CsvSourceParser,
            'json', 'jsonl' => new JsonSourceParser,
            default => throw DrayageException::unreadable("Unsupported format {$format}."),
        };
    }

    // ─────────────────────────────────────────────────────────────────────

    private function process(string $importId): array
    {
        $import = $this->storage->import($importId)
            ?? throw new DrayageException('That import does not exist.', 404);

        // A redelivered job for an import that already ran.
        if ($import['status'] !== 'queued') {
            return $import;
        }

        $started = hrtime(true);
        $import['status'] = 'processing';
        $import['started_at'] = now()->toIso8601String();
        $this->storage->saveImport($import);

        $datasetId = DrayageStorage::newDatasetId();

        try {
            $build = $this->build($import, $datasetId);
        } catch (DrayageException $e) {
            return $this->fail($import, $datasetId, $e->getMessage(), null, $started);
        } catch (\Throwable $e) {
            Log::channel('drayage')->error('Drayage import crashed', [
                'import_id' => $importId,
                'error' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            return $this->fail($import, $datasetId, 'The import stopped on an unexpected error. Nothing was activated.', null, $started);
        }

        if ($build['failure'] !== null) {
            return $this->fail($import, $datasetId, $build['failure'], $build['report'], $started);
        }

        $pointer = $this->activate($datasetId, $import['created_by'], $import['ip'], 'import');

        $pruned = $this->storage->prune(config('drayage.retention'));
        foreach ($pruned as $id) {
            $this->forgetIndexCache($id);
        }

        $report = $build['report'] + [
            'pruned_datasets' => $pruned,
            'backup' => $this->storage->backup($datasetId),
        ];

        $import = array_merge($import, [
            'status' => 'completed',
            'finished_at' => now()->toIso8601String(),
            'duration_ms' => self::elapsed($started),
            'dataset_id' => $datasetId,
            'activated' => true,
            'previous_dataset_id' => $pointer['previous_dataset_id'],
            'report' => $report + ['duration_ms' => self::elapsed($started), 'started_by' => $import['created_by']],
        ]);

        $this->storage->saveImport($import);

        $this->audit->record('import.completed', $import['created_by'], $import['ip'], [
            'import_id' => $importId,
            'dataset_id' => $datasetId,
            'row_count' => $report['rows']['imported'],
            'rejected' => $report['rows']['rejected'],
        ]);

        return $import;
    }

    /**
     * Parses, normalizes, merges and - if the gate passes - writes the
     * dataset. Returns the report, and a failure reason when the gate failed
     * (in which case nothing was written).
     *
     * @return array{report: array, failure: string|null}
     */
    private function build(array $import, string $datasetId): array
    {
        $cap = config('drayage.import.report_cap');
        $parser = $this->parserFor($import['format']);
        $normalizer = new DrayageNormalizer;

        $records = [];
        $extras = [];
        $sourceRows = [];
        $read = 0;
        $rejected = [];
        $rejectedCount = 0;
        $merges = [];
        $mergedCount = 0;
        $warnings = [];
        $warningCount = 0;
        $warningsByField = [];

        foreach ($parser->rows($this->storage->absolute($import['source_storage_path'])) as $parsed) {
            $read++;

            if ($parsed['error'] !== null) {
                $rejectedCount++;
                count($rejected) < $cap && $rejected[] = ['row' => $parsed['row'], 'reason' => $parsed['error']];

                continue;
            }

            $result = $normalizer->normalize($parsed['values']);

            foreach ($result['warnings'] as $warning) {
                $warningCount++;
                $warningsByField[$warning['field']] = ($warningsByField[$warning['field']] ?? 0) + 1;
                count($warnings) < $cap && $warnings[] = ['row' => $parsed['row']] + $warning;
            }

            if ($result['error'] !== null) {
                $rejectedCount++;
                count($rejected) < $cap && $rejected[] = ['row' => $parsed['row'], 'reason' => $result['error']];

                continue;
            }

            $key = $normalizer->carrierKey($result['record']);

            if (isset($records[$key])) {
                $records[$key] = $normalizer->merge($records[$key], $result['record']);
                $extras[$key] = $parsed['extra'] + $extras[$key];
                $mergedCount++;
                count($merges) < $cap && $merges[] = ['carrier_key' => $key, 'row' => $parsed['row'], 'merged_with_rows' => $sourceRows[$key]];
                $sourceRows[$key][] = $parsed['row'];

                continue;
            }

            $records[$key] = $result['record'];
            $extras[$key] = $parsed['extra'];
            $sourceRows[$key] = [$parsed['row']];
        }

        $headers = $parser->headerReport();
        $imported = count($records);

        $report = [
            'rows' => [
                'read' => $read,
                'imported' => $imported,
                'merged' => $mergedCount,
                'rejected' => $rejectedCount,
            ],
            'rejected_rows' => $rejected,
            'rejected_rows_truncated' => $rejectedCount > count($rejected),
            'headers' => [
                'missing' => $headers['missing'],
                'unknown' => $headers['unknown'],
            ],
            'warnings' => [
                'count' => $warningCount,
                'by_field' => $warningsByField,
                'samples' => $warnings,
                'truncated' => $warningCount > count($warnings),
            ],
            'merges' => $merges,
        ];

        $limit = (float) config('drayage.import.max_rejected_percent');
        $rejectedPercent = $read > 0 ? 100 * $rejectedCount / $read : 0;

        if ($imported === 0) {
            return ['report' => $report, 'failure' => 'No rows could be imported.'];
        }

        if ($rejectedPercent > $limit) {
            return ['report' => $report, 'failure' => sprintf('%.1f%% of rows were rejected, above the %s%% limit.', $rejectedPercent, $limit)];
        }

        $report['field_coverage'] = $this->writeDataset($import, $datasetId, $normalizer, $records, $extras, $sourceRows, $report);
        $report['dataset_id'] = $datasetId;

        return ['report' => $report, 'failure' => null];
    }

    /**
     * Writes every carrier file, the index, lookups, facets and manifest,
     * then commits the folder. Returns per-field coverage. $records is taken
     * by reference and emptied as it goes, so the flat records and the index
     * are never both fully in memory.
     */
    private function writeDataset(array $import, string $datasetId, DrayageNormalizer $normalizer, array &$records, array $extras, array $sourceRows, array $report): array
    {
        $this->storage->beginDataset($datasetId);

        try {
            $importedAt = now()->toIso8601String();
            $index = [];
            $lookups = ['usdot' => [], 'mc' => [], 'scac' => []];
            $filled = array_fill_keys(DrayageFields::keys(), 0);
            $types = ['full' => 0, 'listing' => 0];

            foreach (array_keys($records) as $key) {
                $record = $normalizer->derive($records[$key]);
                unset($records[$key]);

                $this->storage->writeCarrier($datasetId, $key, $normalizer->document($key, $record, $extras[$key], [
                    'dataset_id' => $datasetId,
                    'imported_at' => $importedAt,
                    'source_rows' => $sourceRows[$key],
                ]));

                $index[] = $normalizer->indexRow($key, $record);

                foreach ($lookups as $type => $_) {
                    if ($record[$type] !== null) {
                        $lookups[$type][$record[$type]][] = $key;
                    }
                }

                foreach ($filled as $field => $_) {
                    if ($record[$field] !== null && $record[$field] !== []) {
                        $filled[$field]++;
                    }
                }

                $record['record_type'] === DrayageFields::RECORD_TYPES['listing'] ? $types['listing']++ : $types['full']++;
            }

            $total = count($index);
            $coverage = array_map(fn ($n) => round(100 * $n / max(1, $total), 1), $filled);

            $this->storage->writeIndex($datasetId, 'records', $index);
            foreach ($lookups as $type => $map) {
                $this->storage->writeIndex($datasetId, 'lookup_'.$type, $map);
            }
            $this->storage->writeIndex($datasetId, 'facets', (new DrayageQueryEngine)->datasetFacets($index));

            $this->storage->commitDataset($datasetId, [
                'dataset_id' => $datasetId,
                'schema_version' => config('drayage.schema_version'),
                'import_id' => $import['import_id'],
                'source' => config('drayage.source_label'),
                'source_filename' => $import['source_filename'],
                'source_sha256' => $import['source_sha256'],
                'source_storage_path' => $import['source_storage_path'],
                'created_at' => $importedAt,
                'created_by' => $import['created_by'],
                'counts' => [
                    'total' => $total,
                    'full_profiles' => $types['full'],
                    'listings' => $types['listing'],
                    'rows_read' => $report['rows']['read'],
                    'rows_rejected' => $report['rows']['rejected'],
                    'rows_merged' => $report['rows']['merged'],
                ],
                'field_coverage' => $coverage,
                'warnings_summary' => [
                    'count' => $report['warnings']['count'],
                    'by_field' => $report['warnings']['by_field'],
                ],
                'headers' => $report['headers'],
            ]);

            return $coverage;
        } catch (\Throwable $e) {
            $this->storage->discardBuilding($datasetId);

            throw $e;
        }
    }

    private function fail(array $import, string $datasetId, string $reason, ?array $report, int $started): array
    {
        $this->storage->discardBuilding($datasetId);

        $import = array_merge($import, [
            'status' => 'failed',
            'finished_at' => now()->toIso8601String(),
            'duration_ms' => self::elapsed($started),
            'error' => $reason,
            'report' => $report === null ? null : $report + [
                'duration_ms' => self::elapsed($started),
                'started_by' => $import['created_by'],
            ],
        ]);

        $this->storage->saveImport($import);

        event(new DrayageImportFailed($import['import_id'], $reason, $import['created_by']));

        $this->audit->record('import.failed', $import['created_by'], $import['ip'], [
            'import_id' => $import['import_id'],
            'reason' => $reason,
            'dataset_id' => $this->storage->currentDatasetId(),
        ]);

        return $import;
    }

    private function forgetIndexCache(string $datasetId): void
    {
        if (config('drayage.cache.store') !== 'none') {
            Cache::store(config('drayage.cache.store'))->forget(DrayageDirectoryService::indexCacheKey($datasetId));
        }
    }

    private function raiseMemoryLimit(): void
    {
        $current = ini_get('memory_limit');
        $wanted = config('drayage.import.memory_limit');

        if ($current !== '-1' && self::bytes($current) < self::bytes($wanted)) {
            ini_set('memory_limit', $wanted);
        }
    }

    private static function bytes(string $size): int
    {
        $size = trim($size);
        $unit = strtolower(substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
