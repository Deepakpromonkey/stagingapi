<?php

namespace App\Services\Drayage;

use App\Exceptions\DrayageException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads the live drayage dataset: search, detail, lookup, facets, stats.
 *
 * The search index (index/records.json) is decoded once per dataset and
 * cached under its dataset id, so activating another dataset switches every
 * reader over on the next request with nothing to invalidate. Within one
 * request it is also held in memory - bound as a scoped singleton, so the
 * list endpoint's search and its facet pass share one decode.
 *
 * The live pointer itself is never cached, not even within a request: it
 * is one small file, and reading it fresh is what makes a rollback take
 * effect without a restart.
 */
class DrayageDirectoryService
{
    /** @var array<string, list<array>> */
    private array $records = [];

    public function __construct(
        private DrayageStorage $storage,
        private DrayageQueryEngine $engine,
    ) {}

    public static function indexCacheKey(string $datasetId): string
    {
        return 'drayage:index:'.$datasetId.':v'.config('drayage.schema_version');
    }

    /**
     * The live pointer, or a 404 when nothing has been imported yet.
     */
    public function current(): array
    {
        return $this->storage->current() ?? throw DrayageException::noDataset();
    }

    public function datasetId(): string
    {
        return $this->current()['dataset_id'];
    }

    /**
     * @return list<array>
     */
    public function records(?string $datasetId = null): array
    {
        $datasetId ??= $this->datasetId();

        return $this->records[$datasetId] ??= $this->loadRecords($datasetId);
    }

    /**
     * One page of results, projected to the requested fields.
     */
    public function search(DrayageQuery $query): array
    {
        $result = $this->engine->run($this->records(), $query);

        $total = $result['total'];
        $lastPage = max(1, (int) ceil($total / $query->perPage));
        $pageRows = array_slice($result['rows'], ($query->page - 1) * $query->perPage, $query->perPage);

        return [
            'items' => $this->project($pageRows, $query->fields),
            'pagination' => [
                'current_page' => $query->page,
                'last_page' => $lastPage,
                'per_page' => $query->perPage,
                'total' => $total,
            ],
            'facets' => $result['facets'],
            'stats' => $result['stats'],
            // Unprojected, for enrichment that needs a USDOT the caller did
            // not ask to see.
            'page_rows' => $pageRows,
        ];
    }

    /**
     * Every matching row, in order - for export.
     *
     * @return list<array>
     */
    public function matching(DrayageQuery $query): array
    {
        $unpaged = new DrayageQuery(
            q: $query->q,
            filters: $query->filters,
            sort: $query->sort,
            page: 1,
            perPage: PHP_INT_MAX,
            fields: $query->fields,
            facets: false,
        );

        return $this->engine->run($this->records(), $unpaged)['rows'];
    }

    public function find(string $carrierKey): ?array
    {
        if (! DrayageStorage::isCarrierKey($carrierKey)) {
            return null;
        }

        return $this->storage->carrier($this->datasetId(), $carrierKey);
    }

    /**
     * Carrier documents for a batch of keys, in the order given.
     *
     * @return array<string, array>
     */
    public function documents(array $carrierKeys): array
    {
        $documents = [];

        foreach ($carrierKeys as $key) {
            if ($document = $this->find($key)) {
                $documents[$key] = $document;
            }
        }

        return $documents;
    }

    /**
     * Carriers with this USDOT, MC or SCAC. MC and SCAC can name several
     * terminals of one company, so this is always a list.
     *
     * @return list<array> flat records
     */
    public function lookup(string $type, string $value): array
    {
        $value = $type === 'scac' ? strtoupper(trim($value)) : preg_replace('/\D/', '', $value);

        $map = $this->storage->index($this->datasetId(), 'lookup_'.$type) ?? [];
        $keys = $map[$value] ?? [];

        return array_values(array_map(
            fn (array $document) => DrayageNormalizer::flatten($document),
            $this->documents($keys)
        ));
    }

    public function facets(): array
    {
        return $this->storage->index($this->datasetId(), 'facets') ?? [];
    }

    public function stats(): array
    {
        $current = $this->current();
        $manifest = $this->storage->manifest($current['dataset_id']) ?? [];
        $facets = $this->facets();

        return [
            'source' => config('drayage.source_label'),
            'dataset' => [
                'dataset_id' => $current['dataset_id'],
                'activated_at' => $current['activated_at'],
                'created_at' => $manifest['created_at'] ?? null,
                'source_filename' => $manifest['source_filename'] ?? null,
                'schema_version' => $manifest['schema_version'] ?? null,
            ],
            'totals' => [
                'carriers' => $manifest['counts']['total'] ?? null,
                'full_profiles' => $manifest['counts']['full_profiles'] ?? null,
                'listings' => $manifest['counts']['listings'] ?? null,
            ],
            'metros' => $facets['values']['metros'] ?? [],
            'field_coverage' => $manifest['field_coverage'] ?? [],
        ];
    }

    /**
     * Rows trimmed to the requested fields. Fields the index does not carry
     * (descriptions, street, the notes) come from the carriers' own files,
     * read only for the rows on this page.
     */
    public function project(array $rows, array $fields): array
    {
        $indexed = array_flip(array_merge(DrayageFields::indexed(), ['carrier_key', 'summary']));
        $needsDocuments = array_diff($fields, array_keys($indexed)) !== [];

        $documents = $needsDocuments ? $this->documents(array_column($rows, 'carrier_key')) : [];

        return array_map(function (array $row) use ($fields, $documents) {
            $source = isset($documents[$row['carrier_key']])
                ? DrayageNormalizer::flatten($documents[$row['carrier_key']]) + $row
                : $row;

            $item = [];
            foreach ($fields as $field) {
                $item[$field] = $source[$field] ?? null;
            }

            return $item;
        }, $rows);
    }

    private function loadRecords(string $datasetId): array
    {
        $store = config('drayage.cache.store');

        if ($store === 'none') {
            return $this->readRecords($datasetId);
        }

        $cache = Cache::store($store);
        $key = self::indexCacheKey($datasetId);

        try {
            $cached = $cache->get($key);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            Log::channel('drayage')->warning('Drayage index cache read failed', ['error' => $e->getMessage()]);
        }

        $records = $this->readRecords($datasetId);

        try {
            $cache->put($key, $records, config('drayage.cache.ttl'));
        } catch (\Throwable $e) {
            // Too big for the store, or the store is down: the file read
            // above is the fallback on every request until it is fixed.
            Log::channel('drayage')->warning('Drayage index cache write failed', ['error' => $e->getMessage()]);
        }

        return $records;
    }

    private function readRecords(string $datasetId): array
    {
        return $this->storage->index($datasetId, 'records')
            ?? throw new DrayageException('The live drayage dataset is unreadable.', 500);
    }
}
