<?php

namespace App\Services\Drayage;

/**
 * Turns one parsed row into a typed, flat carrier record, and a flat record
 * into the nested document and the index row.
 *
 * The rules that matter most:
 *  - booleans are tri-state. Blank is null ("the profile does not say"),
 *    never false;
 *  - identifiers stay strings, so a ZIP of 07105 or a bond number with a
 *    leading zero survives;
 *  - a value that cannot be read becomes null with a warning on the row,
 *    rather than rejecting the row. Only a row with no company name is
 *    rejected, because there is nothing to call it.
 *
 * No I/O here: everything is a pure function of its input, which is what
 * lets the tests pin exact values.
 */
class DrayageNormalizer
{
    public const US_STATES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA',
        'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM',
        'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'PR', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA',
        'WA', 'WV', 'WI', 'WY',
    ];

    public const CA_PROVINCES = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];

    /** Capabilities named in a carrier's one-line summary when stated yes. */
    private const SUMMARY_CAPABILITIES = [
        'ocean_port' => 'port', 'rail_ramp' => 'rail', 'hazmat' => 'hazmat', 'reefer_drayage' => 'reefer',
        'overweight' => 'overweight', 'oog' => 'OOG', 'flat_rack' => 'flat rack', 'open_top' => 'open top',
        'iso_tank' => 'ISO tank', 'twic' => 'TWIC', 'private_chassis' => 'own chassis', 'transload' => 'transload',
        'customs_bonded' => 'bonded', 'cross_border_canada' => 'Canada', 'cross_border_mexico' => 'Mexico',
    ];

    private int $currentYear;

    public function __construct(?int $currentYear = null)
    {
        $this->currentYear = $currentYear ?? (int) now()->format('Y');
    }

    /**
     * @param  array<string, mixed>  $values  raw values keyed by dictionary key
     * @return array{record: array|null, warnings: list<array>, error: string|null}
     */
    public function normalize(array $values): array
    {
        $warnings = [];
        $record = [];

        foreach (DrayageFields::all() as $key => $field) {
            $record[$key] = $this->cast($key, $field['type'], $values[$key] ?? null, $warnings);
        }

        if ($record['company_name'] === null) {
            return ['record' => null, 'warnings' => $warnings, 'error' => 'Company is blank.'];
        }

        return ['record' => $record, 'warnings' => $warnings, 'error' => null];
    }

    /**
     * lm-{LoadMatch ID} when there is one; otherwise ls- and a hash of the
     * normalized name and HQ, which comes out the same on every re-import of
     * the same listing.
     */
    public function carrierKey(array $record): string
    {
        if ($record['loadmatch_id'] !== null) {
            return 'lm-'.$record['loadmatch_id'];
        }

        $basis = implode('|', array_map(
            fn ($v) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $v))),
            [$record['company_name'], $record['hq_city'], $record['hq_state']]
        ));

        return 'ls-'.substr(hash('sha256', $basis), 0, 12);
    }

    public function filledCount(array $record): int
    {
        return count(array_filter($record, fn ($v) => $v !== null && $v !== []));
    }

    /**
     * Two rows for the same carrier: the fuller one wins, and the lists that
     * describe reach (metros, codes, emails, phones, terminals) are unioned.
     */
    public function merge(array $base, array $other): array
    {
        if ($this->filledCount($other) > $this->filledCount($base)) {
            [$base, $other] = [$other, $base];
        }

        foreach (DrayageFields::MERGE_UNION as $key) {
            $union = $this->uniqueList(array_merge($base[$key] ?? [], $other[$key] ?? []));
            $base[$key] = $union === [] ? null : $union;
        }

        return $base;
    }

    /**
     * Fields computed from others, recomputed even when the file has them so
     * every carrier is scored by the same rule.
     */
    public function derive(array $record): array
    {
        if ($record['established'] !== null && $record['established'] >= 1800 && $record['established'] <= $this->currentYear) {
            $record['years_in_business'] = $this->currentYear - $record['established'];
        } elseif ($record['authority_date'] !== null) {
            $record['years_in_business'] = max(0, $this->currentYear - (int) substr($record['authority_date'], 0, 4));
        } else {
            $record['years_in_business'] = null;
        }

        $drivers = ($record['company_drivers'] ?? 0) + ($record['owner_operators'] ?? 0);
        $record['owner_op_pct'] = $record['company_drivers'] !== null && $record['owner_operators'] !== null && $drivers > 0
            ? (int) round(100 * $record['owner_operators'] / $drivers)
            : null;

        $present = count(array_filter(
            DrayageFields::COMPLETENESS_FIELDS,
            fn ($key) => $record[$key] !== null && $record[$key] !== []
        ));
        $record['completeness'] = (int) round(100 * $present / count(DrayageFields::COMPLETENESS_FIELDS));

        return $record;
    }

    public function searchText(array $record): string
    {
        $parts = [];

        foreach (DrayageFields::SEARCH_FIELDS as $key) {
            $value = $record[$key] ?? null;
            $parts[] = is_array($value) ? implode(' ', $value) : (string) $value;
        }

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(implode(' ', $parts))));
    }

    /**
     * One line per carrier for an LLM to read: name, where, identifiers,
     * and what it says it can do.
     */
    public function summary(array $record): string
    {
        $parts = [$record['company_name']];

        if ($record['metros']) {
            $parts[] = implode(', ', array_slice($record['metros'], 0, 3)).(count($record['metros']) > 3 ? ' +'.(count($record['metros']) - 3).' more' : '');
        } elseif ($record['hq_city'] || $record['hq_state']) {
            $parts[] = trim($record['hq_city'].', '.$record['hq_state'], ', ');
        }

        $ids = array_filter([
            $record['scac'] ? 'SCAC '.$record['scac'] : null,
            $record['mc'] ? 'MC '.$record['mc'] : null,
            $record['usdot'] ? 'DOT '.$record['usdot'] : null,
        ]);
        if ($ids) {
            $parts[] = implode(' / ', $ids);
        }

        $capabilities = [];
        foreach (self::SUMMARY_CAPABILITIES as $key => $label) {
            if ($record[$key] === true) {
                $capabilities[] = $label;
            }
        }
        if ($capabilities) {
            $parts[] = implode(', ', $capabilities);
        }

        if ($record['drivers_approx'] !== null) {
            $parts[] = '~'.$record['drivers_approx'].' drivers';
        }

        $parts[] = $record['record_type'] === DrayageFields::RECORD_TYPES['listing'] ? 'directory listing' : 'full profile';

        return implode(' · ', $parts);
    }

    /**
     * The carrier file: sections in the documented order, meta first and the
     * unrecognized columns last.
     */
    public function document(string $carrierKey, array $record, array $extra, array $meta): array
    {
        $document = array_fill_keys(DrayageFields::SECTIONS, []);

        $document['meta'] = [
            'carrier_key' => $carrierKey,
            'loadmatch_id' => $record['loadmatch_id'],
            'record_type' => $record['record_type'],
            'dataset_id' => $meta['dataset_id'],
            'imported_at' => $meta['imported_at'],
            'completeness' => $record['completeness'],
            'source' => config('drayage.source_label'),
            'summary' => $this->summary($record),
            'source_rows' => $meta['source_rows'],
        ];

        foreach (DrayageFields::all() as $key => $field) {
            if ($field['section'] !== 'meta') {
                $document[$field['section']][$key] = $record[$key];
            }
        }

        $document['extra'] = $extra === [] ? new \stdClass : $extra;

        return $document;
    }

    /**
     * The carrier's row in index/records.json. Nulls are left out to keep the
     * file small - a missing key reads as null.
     */
    public function indexRow(string $carrierKey, array $record): array
    {
        $row = ['carrier_key' => $carrierKey];

        foreach (DrayageFields::indexed() as $key) {
            if (array_key_exists($key, $record) && $record[$key] !== null) {
                $row[$key] = $record[$key];
            }
        }

        if ($record['authority_date'] !== null) {
            $row['authority_year'] = (int) substr($record['authority_date'], 0, 4);
        }
        if ($record['first_added'] !== null) {
            $row['first_added_year'] = (int) substr($record['first_added'], 0, 4);
        }

        $row['search_text'] = $this->searchText($record);
        $row['summary'] = $this->summary($record);

        return $row;
    }

    /**
     * The flat record back out of a carrier document.
     */
    public static function flatten(array $document): array
    {
        $flat = [
            'carrier_key' => $document['meta']['carrier_key'] ?? null,
            'loadmatch_id' => $document['meta']['loadmatch_id'] ?? null,
            'record_type' => $document['meta']['record_type'] ?? null,
            'completeness' => $document['meta']['completeness'] ?? null,
            'summary' => $document['meta']['summary'] ?? null,
        ];

        foreach (DrayageFields::SECTIONS as $section) {
            if ($section !== 'meta' && $section !== 'extra') {
                $flat += $document[$section] ?? [];
            }
        }

        return $flat;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Casting
    // ─────────────────────────────────────────────────────────────────────

    private function cast(string $key, string $type, mixed $value, array &$warnings): mixed
    {
        if ($type === 'list') {
            return $this->castList($key, $value, $warnings);
        }

        if (is_array($value)) {
            $value = implode('; ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        if (is_bool($value)) {
            return $type === 'bool' ? $value : $this->warn($warnings, $key, $value, 'Expected text, got a boolean.');
        }

        $text = $value === null ? '' : trim(preg_replace('/\s+/u', ' ', (string) $value));

        if ($text === '') {
            return null;
        }

        return match ($type) {
            'bool' => $this->castBool($key, $text, $warnings),
            'int' => $this->castNumber($key, $text, $warnings, integer: true),
            'money' => $this->castNumber($key, $text, $warnings, integer: false),
            'date' => $this->castDate($key, $text, $warnings),
            'enum' => $this->castRecordType($key, $text, $warnings),
            default => $this->castString($key, $text, $warnings),
        };
    }

    private function castBool(string $key, string $text, array &$warnings): ?bool
    {
        return match (strtolower($text)) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => $this->warn($warnings, $key, $text, 'Expected Yes or No.'),
        };
    }

    /**
     * "$" and "," are stripped defensively; anything else that is not a
     * plain number ("100k", "N/A") is a warning rather than a guess.
     */
    private function castNumber(string $key, string $text, array &$warnings, bool $integer): int|float|null
    {
        $clean = str_replace(['$', ',', ' ', '%'], '', $text);

        if (! preg_match('/^\d+(\.\d+)?$/', $clean)) {
            return $this->warn($warnings, $key, $text, 'Expected a number.');
        }

        $number = (float) $clean;

        if ($integer || floor($number) === $number) {
            return (int) round($number);
        }

        return $number;
    }

    private function castDate(string $key, string $text, array &$warnings): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $text;
        }

        return $this->warn($warnings, $key, $text, 'Expected a date as YYYY-MM-DD.');
    }

    private function castRecordType(string $key, string $text, array &$warnings): ?string
    {
        $lower = strtolower($text);

        foreach (DrayageFields::RECORD_TYPES as $slug => $label) {
            if ($lower === $slug || $lower === strtolower($label)) {
                return $label;
            }
        }

        return $this->warn($warnings, $key, $text, 'Expected "Full profile" or "Directory listing".');
    }

    private function castString(string $key, string $text, array &$warnings): ?string
    {
        switch ($key) {
            case 'usdot':
            case 'mc':
                // "MC-123456" and "123 456" both mean 123456; the digits are
                // kept as a string so leading zeros survive.
                $digits = preg_replace('/\D/', '', $text);

                return $digits !== '' ? $digits : $this->warn($warnings, $key, $text, 'Expected digits.');

            case 'scac':
                $scac = strtoupper($text);

                return preg_match('/^[A-Z0-9]{2,4}$/', $scac) ? $scac : $this->warn($warnings, $key, $text, 'Expected a 2-4 character SCAC.');

            case 'loadmatch_id':
                return preg_match('/^[A-Za-z0-9]{1,32}$/', $text) ? $text : $this->warn($warnings, $key, $text, 'Expected an alphanumeric LoadMatch ID.');

            case 'hq_state':
                return strlen($text) === 2 ? strtoupper($text) : $text;

            default:
                return $text;
        }
    }

    private function castList(string $key, mixed $value, array &$warnings): ?array
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        $items = is_array($value)
            ? array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)
            : explode(';', (string) $value);

        $items = array_map(fn ($v) => trim(preg_replace('/\s+/u', ' ', $v)), $items);
        $items = array_values(array_filter($items, fn ($v) => $v !== ''));

        $valid = match ($key) {
            'states_served' => self::US_STATES,
            'provinces_served' => self::CA_PROVINCES,
            default => null,
        };

        if ($valid !== null || $key === 'city_codes') {
            $items = array_map('strtoupper', $items);
        }

        if ($valid !== null) {
            $invalid = array_diff($items, $valid);

            if ($invalid !== []) {
                $this->warn($warnings, $key, implode('; ', $invalid), 'Dropped codes that are not valid '.($key === 'states_served' ? 'U.S. states' : 'Canadian provinces').'.');
                $items = array_values(array_intersect($items, $valid));
            }
        }

        $items = $this->uniqueList($items);

        return $items === [] ? null : $items;
    }

    /** De-duplicates case-insensitively, keeping the first spelling and the order. */
    private function uniqueList(array $items): array
    {
        $seen = [];
        $unique = [];

        foreach ($items as $item) {
            $fold = mb_strtolower($item);

            if (! isset($seen[$fold])) {
                $seen[$fold] = true;
                $unique[] = $item;
            }
        }

        return $unique;
    }

    /**
     * Records the problem and returns null, so a cast can `return $this->warn(...)`.
     * The value is quoted only for fields that are not contact details.
     */
    private function warn(array &$warnings, string $key, mixed $value, string $message): null
    {
        $warning = ['field' => $key, 'message' => $message];

        if (! in_array($key, DrayageFields::CONTACT_FIELDS, true)) {
            $warning['value'] = mb_substr(is_scalar($value) ? (string) $value : '', 0, 60);
        }

        $warnings[] = $warning;

        return null;
    }
}
