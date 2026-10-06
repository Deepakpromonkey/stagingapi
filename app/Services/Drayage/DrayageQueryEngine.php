<?php

namespace App\Services\Drayage;

/**
 * Filtering, faceting, ranking and sorting over the index rows of one
 * dataset - plain PHP over an array, no I/O.
 *
 * Facets use exclude-self semantics: each facet is counted over the rows
 * that pass every active filter except its own, so its options show what
 * changing that one filter would give. Done in one pass: each row is tested
 * against every filter once, and a row that fails exactly one filter is
 * kept aside for that filter's facet alone. A row failing two or more
 * counts nowhere. That keeps a page with ten filters and ~80 facets over
 * ~5k carriers to a few tens of milliseconds.
 */
class DrayageQueryEngine
{
    private const DEFAULT_SORT = [['completeness', 'desc'], ['company_name', 'asc']];

    /** Booleans whose share of "yes" is reported in the summary stats. */
    private const STATS_BOOLEANS = ['hazmat', 'reefer_drayage', 'twic', 'private_chassis'];

    /**
     * @param  list<array>  $records  index rows
     * @return array{rows: list<array>, total: int, facets: array|null, stats: array}
     */
    public function run(array $records, DrayageQuery $query): array
    {
        $predicates = [];
        foreach ($query->filters as $filter) {
            $predicates[$filter['id']] = $this->predicate($filter);
        }

        $matches = [];
        $nearMisses = [];

        foreach ($records as $record) {
            $failedId = null;
            $failures = 0;

            foreach ($predicates as $id => $passes) {
                if (! $passes($record)) {
                    $failedId = $id;

                    if (++$failures > 1) {
                        break;
                    }
                }
            }

            if ($failures === 0) {
                $matches[] = $record;
            } elseif ($failures === 1) {
                $nearMisses[$failedId][] = $record;
            }
        }

        $facets = $query->facets ? $this->facets($matches, $nearMisses) : null;

        return [
            'rows' => $this->sort($matches, $query),
            'total' => count($matches),
            'facets' => $facets,
            'stats' => $this->stats($matches),
        ];
    }

    /**
     * Facet counts and numeric bounds for a whole dataset, with no filters -
     * what index/facets.json holds.
     */
    public function datasetFacets(array $records): array
    {
        $facets = $this->facets($records, []);

        $bounds = [];
        foreach (DrayageFields::ranges() as $key) {
            $values = array_values(array_filter(array_column($records, $key), fn ($v) => $v !== null));

            $bounds[$key] = $values === []
                ? ['min' => null, 'max' => null, 'count' => 0]
                : ['min' => min($values), 'max' => max($values), 'count' => count($values)];
        }

        return $facets + ['bounds' => $bounds, 'total' => count($records)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filters
    // ─────────────────────────────────────────────────────────────────────

    private function predicate(array $filter): \Closure
    {
        $key = $filter['key'];
        $value = $filter['value'];

        return match ($filter['kind']) {
            'text' => $this->textPredicate($value),

            'bool' => match ($value) {
                'yes' => fn (array $r) => ($r[$key] ?? null) === true,
                'no' => fn (array $r) => ($r[$key] ?? null) === false,
                default => fn (array $r) => ($r[$key] ?? null) === null,
            },

            'presence' => $key === 'canadian_authority'
                ? fn (array $r) => isset($r['nsc']) || isset($r['cvor']) || isset($r['nir'])
                : fn (array $r) => self::present($r[$key] ?? null),

            'range' => function (array $r) use ($key, $value) {
                $v = $r[$key] ?? null;

                return $v !== null
                    && ($value[0] === null || $v >= $value[0])
                    && ($value[1] === null || $v <= $value[1]);
            },

            'multi' => $this->multiPredicate($key, $value, $filter['mode'] ?? 'any'),

            'recency' => fn (array $r) => isset($r['last_updated']) && $r['last_updated'] >= $value,
        };
    }

    private function multiPredicate(string $key, array $wanted, string $mode): \Closure
    {
        $wanted = array_map('mb_strtolower', $wanted);

        return function (array $r) use ($key, $wanted, $mode) {
            $have = $r[$key] ?? null;

            if ($have === null) {
                return false;
            }

            $have = array_map('mb_strtolower', (array) $have);

            if ($mode === 'all') {
                return array_diff($wanted, $have) === [];
            }

            return array_intersect($wanted, $have) !== [];
        };
    }

    /**
     * Every token must appear in the carrier's search text - except that an
     * exact identifier (USDOT, MC, SCAC, LoadMatch ID) matches on its own,
     * so "MC-1098356" finds the carrier even though the text holds 1098356.
     */
    private function textPredicate(string $q): \Closure
    {
        $tokens = self::tokens($q);
        $identifier = self::identifier($q);

        return function (array $r) use ($tokens, $identifier) {
            if ($identifier !== null && self::identifierMatches($r, $identifier)) {
                return true;
            }

            $text = $r['search_text'] ?? '';

            foreach ($tokens as $token) {
                if (! str_contains($text, $token)) {
                    return false;
                }
            }

            return true;
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Facets
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array>  $matches  rows passing every filter
     * @param  array<string, list<array>>  $nearMisses  rows failing only the keyed filter
     */
    private function facets(array $matches, array $nearMisses): array
    {
        $booleans = [];
        foreach (DrayageFields::booleans() as $key) {
            $booleans[$key] = $this->countBoolean(self::withNearMisses($matches, $nearMisses, $key), $key);
        }

        $presence = [];
        foreach (DrayageFields::PRESENCE as $key) {
            $rows = self::withNearMisses($matches, $nearMisses, 'has:'.$key);
            $present = 0;

            foreach ($rows as $r) {
                if ($key === 'canadian_authority' ? (isset($r['nsc']) || isset($r['cvor']) || isset($r['nir'])) : self::present($r[$key] ?? null)) {
                    $present++;
                }
            }

            $presence[$key] = ['present' => $present, 'absent' => count($rows) - $present];
        }

        $values = [];
        foreach (DrayageFields::MULTI_VALUE as $key) {
            $values[$key] = $this->countValues(self::withNearMisses($matches, $nearMisses, $key), $key);
        }

        // record_type reads better as its slug + label.
        $labels = array_flip(DrayageFields::RECORD_TYPES);
        $values['record_type'] = array_map(fn ($o) => [
            'value' => $labels[$o['value']] ?? $o['value'],
            'label' => $o['value'],
            'count' => $o['count'],
        ], $values['record_type']);

        return compact('booleans', 'presence', 'values');
    }

    /** The rows a facet counts over: the matches, plus those failing only its own filter. */
    private static function withNearMisses(array $matches, array $nearMisses, string $id): array
    {
        return isset($nearMisses[$id]) ? array_merge($matches, $nearMisses[$id]) : $matches;
    }

    private function countBoolean(array $rows, string $key): array
    {
        $counts = ['yes' => 0, 'no' => 0, 'unknown' => 0];

        foreach ($rows as $r) {
            $v = $r[$key] ?? null;
            $counts[$v === true ? 'yes' : ($v === false ? 'no' : 'unknown')]++;
        }

        return $counts;
    }

    /**
     * Options with counts, most common first. Values are compared
     * case-insensitively and reported in the first spelling seen.
     */
    private function countValues(array $rows, string $key): array
    {
        $counts = [];
        $spelling = [];

        foreach ($rows as $r) {
            $have = $r[$key] ?? null;

            if ($have === null) {
                continue;
            }

            foreach (array_unique((array) $have) as $value) {
                $fold = mb_strtolower($value);
                $spelling[$fold] ??= $value;
                $counts[$fold] = ($counts[$fold] ?? 0) + 1;
            }
        }

        $options = [];
        foreach ($counts as $fold => $count) {
            $options[] = ['value' => $spelling[$fold], 'count' => $count];
        }

        usort($options, fn ($a, $b) => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

        return $options;
    }

    private function stats(array $matches): array
    {
        $yesShare = [];
        foreach (self::STATS_BOOLEANS as $key) {
            $counts = $this->countBoolean($matches, $key);
            $stated = $counts['yes'] + $counts['no'];
            $yesShare[$key] = $stated > 0 ? round(100 * $counts['yes'] / $stated, 1) : null;
        }

        return [
            'total' => count($matches),
            'median_drivers_approx' => self::median(array_column($matches, 'drivers_approx')),
            'median_cargo_insurance' => self::median(array_column($matches, 'cargo_insurance')),
            'pct_yes_of_stated' => $yesShare,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sorting
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Explicit sort if given; otherwise relevance when there is free text,
     * then the default order. Nulls always last, either direction.
     */
    private function sort(array $rows, DrayageQuery $query): array
    {
        $order = $query->sort !== [] ? $query->sort : self::DEFAULT_SORT;
        $order[] = ['company_name', 'asc'];
        $order[] = ['carrier_key', 'asc'];

        $relevance = $query->sort === [] && $query->q !== null;

        if ($relevance) {
            $tokens = self::tokens($query->q);
            $identifier = self::identifier($query->q);
            $phrase = mb_strtolower(trim($query->q));

            foreach ($rows as $i => $row) {
                $rows[$i]['_score'] = self::relevance($row, $tokens, $identifier, $phrase);
            }
        }

        usort($rows, function (array $a, array $b) use ($order, $relevance) {
            if ($relevance && $a['_score'] !== $b['_score']) {
                return $b['_score'] <=> $a['_score'];
            }

            foreach ($order as [$key, $direction]) {
                $x = $a[$key] ?? null;
                $y = $b[$key] ?? null;

                if ($x === $y) {
                    continue;
                }
                if ($x === null) {
                    return 1;
                }
                if ($y === null) {
                    return -1;
                }

                $cmp = is_string($x) && is_string($y) ? strnatcasecmp($x, $y) : $x <=> $y;

                if ($cmp !== 0) {
                    return $direction === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        return $rows;
    }

    /**
     * 1000 exact identifier, 500 name starts with the query, 200 every token
     * in the name, 100 matched elsewhere.
     */
    private static function relevance(array $row, array $tokens, ?string $identifier, string $phrase): int
    {
        if ($identifier !== null && self::identifierMatches($row, $identifier)) {
            return 1000;
        }

        $name = mb_strtolower($row['company_name'] ?? '');

        if ($phrase !== '' && str_starts_with($name, $phrase)) {
            return 500;
        }

        foreach ($tokens as $token) {
            if (! str_contains($name, $token)) {
                return 100;
            }
        }

        return 200;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    public static function tokens(string $q): array
    {
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($q))) ?: [];
        $tokens = array_map(fn ($t) => trim($t, ",;:()\"'"), $tokens);

        return array_values(array_filter($tokens, fn ($t) => $t !== ''));
    }

    /**
     * The query read as a single identifier, if it looks like one: digits
     * with an optional USDOT/DOT/MC prefix, or one alphanumeric word (a SCAC).
     */
    public static function identifier(string $q): ?string
    {
        $q = trim($q);

        if (preg_match('/^(?:us\s*dot|dot|mc|mx|ff)?[\s#:.-]*(\d{1,12})$/i', $q, $m)) {
            return $m[1];
        }

        if (preg_match('/^[A-Za-z0-9]{2,32}$/', $q)) {
            return strtolower($q);
        }

        return null;
    }

    private static function identifierMatches(array $row, string $identifier): bool
    {
        return ($row['usdot'] ?? null) === $identifier
            || ($row['mc'] ?? null) === $identifier
            || strtolower($row['scac'] ?? '') === $identifier
            || strtolower($row['loadmatch_id'] ?? '') === $identifier;
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private static function median(array $values): int|float|null
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));

        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
