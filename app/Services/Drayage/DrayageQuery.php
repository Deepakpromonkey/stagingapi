<?php

namespace App\Services\Drayage;

/**
 * A validated list/export request, as the query engine consumes it.
 *
 * Built from the request's validated input by fromInput(); the form request
 * has already refused unknown keys and bad values, so nothing here
 * re-validates.
 */
class DrayageQuery
{
    /**
     * @param  list<array{id: string, kind: string, key: string, value: mixed, mode?: string}>  $filters
     * @param  list<array{0: string, 1: string}>  $sort  [key, asc|desc]; empty = relevance or default
     */
    public function __construct(
        public readonly ?string $q = null,
        public readonly array $filters = [],
        public readonly array $sort = [],
        public readonly int $page = 1,
        public readonly int $perPage = 25,
        public readonly array $fields = DrayageFields::CARD_FIELDS,
        public readonly bool $facets = true,
    ) {}

    public static function fromInput(array $input): self
    {
        $filters = [];

        $q = isset($input['q']) ? trim((string) $input['q']) : '';
        if ($q !== '') {
            $filters[] = ['id' => 'q', 'kind' => 'text', 'key' => 'search_text', 'value' => $q];
        }

        // record_type=full|listing|all, or record_type[]= like any multi key.
        $recordTypes = (array) ($input['record_type'] ?? []);
        if ($recordTypes !== [] && ! in_array('all', $recordTypes, true)) {
            $filters[] = [
                'id' => 'record_type',
                'kind' => 'multi',
                'key' => 'record_type',
                'value' => array_values(array_map(fn ($t) => DrayageFields::RECORD_TYPES[$t], array_unique($recordTypes))),
                'mode' => 'any',
            ];
        }

        foreach (DrayageFields::booleans() as $key) {
            if (isset($input[$key])) {
                $filters[] = ['id' => $key, 'kind' => 'bool', 'key' => $key, 'value' => $input[$key]];
            }
        }

        foreach (array_unique((array) ($input['has'] ?? [])) as $key) {
            $filters[] = ['id' => 'has:'.$key, 'kind' => 'presence', 'key' => $key, 'value' => true];
        }

        foreach (DrayageFields::ranges() as $key) {
            $min = $input[$key.'_min'] ?? null;
            $max = $input[$key.'_max'] ?? null;

            if ($min !== null || $max !== null) {
                $filters[] = [
                    'id' => 'range:'.$key,
                    'kind' => 'range',
                    'key' => $key,
                    'value' => [$min === null ? null : (float) $min, $max === null ? null : (float) $max],
                ];
            }
        }

        foreach (DrayageFields::MULTI_VALUE as $key) {
            if ($key === 'record_type' || empty($input[$key])) {
                continue;
            }

            $filters[] = [
                'id' => $key,
                'kind' => 'multi',
                'key' => $key,
                'value' => array_values(array_unique((array) $input[$key])),
                'mode' => $input[$key.'_mode'] ?? 'any',
            ];
        }

        if (isset($input['updated_within_days'])) {
            $filters[] = [
                'id' => 'recency',
                'kind' => 'recency',
                'key' => 'last_updated',
                'value' => now()->subDays((int) $input['updated_within_days'])->format('Y-m-d'),
                'days' => (int) $input['updated_within_days'],
            ];
        }

        $sort = [];
        foreach (array_filter(explode(',', (string) ($input['sort'] ?? ''))) as $term) {
            $term = trim($term);
            $sort[] = str_starts_with($term, '-') ? [substr($term, 1), 'desc'] : [$term, 'asc'];
        }

        $fields = isset($input['fields']) && trim((string) $input['fields']) !== ''
            ? array_values(array_unique(array_map('trim', explode(',', $input['fields']))))
            : DrayageFields::CARD_FIELDS;

        if (! in_array('carrier_key', $fields, true)) {
            array_unshift($fields, 'carrier_key');
        }

        return new self(
            q: $q !== '' ? $q : null,
            filters: $filters,
            sort: $sort,
            page: max(1, (int) ($input['page'] ?? 1)),
            perPage: (int) ($input['per_page'] ?? config('drayage.pagination.default_per_page')),
            fields: $fields,
            facets: ! in_array((string) ($input['facets'] ?? 'true'), ['false', '0'], true),
        );
    }

    /**
     * The filters as an audit entry records them: ids and values, nothing
     * computed.
     */
    public function describeFilters(): array
    {
        $described = [];

        foreach ($this->filters as $filter) {
            $described[$filter['id']] = match (true) {
                $filter['kind'] === 'recency' => ['days' => $filter['days']],
                ($filter['mode'] ?? null) === 'all' => ['all' => $filter['value']],
                default => $filter['value'],
            };
        }

        return $described;
    }
}
