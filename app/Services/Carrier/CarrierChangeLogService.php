<?php

namespace App\Services\Carrier;

use App\Console\Commands\BuildCarrierChangeLogIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * FMCSA's field-level change history, read out of the newline-delimited JSON
 * export on S3 without ever putting it in a database.
 *
 * The export is gigabytes of one-change-per-line records covering every DOT
 * number in the country, but it is grouped: a carrier's changes sit together in
 * one contiguous run of bytes. `carriers:index-change-log` records where each
 * run starts and how long it is, so answering "what has this carrier changed?"
 * costs a binary search over a small local index plus a single ranged GET for
 * the ten-odd kilobytes that belong to that carrier — no scan, no import.
 *
 * @see BuildCarrierChangeLogIndex
 */
class CarrierChangeLogService
{
    /** Index header: magic, version, entry count, source size, built at, source etag. */
    protected const MAGIC = 'DTCLIDX1';

    protected const HEADER_BYTES = 128;

    /** dot (uint64), offset (uint64), length (uint32). */
    protected const ENTRY_BYTES = 20;

    /**
     * Which contact field belongs to which card on the profile.
     *
     * Named exactly as FMCSA names them in the export, because that is the only
     * key the file gives us. Anything not listed here is a change to something
     * other than how the carrier is contacted — equipment, cargo, safety
     * ratings — and is left out of contact history entirely.
     */
    protected const FIELD_GROUPS = [
        'name' => [
            'Legal Name (from DOT Record)',
            'Legal Name (from MC Record)',
            'DBA Name (from DOT Record)',
            'DBA Name (from MC Record)',
        ],

        'email' => [
            'Email Address',
        ],

        'phone' => [
            'Office Telephone Number',
            'Cell Phone Number',
            'Business Telephone',
            'Mailing Telephone',
        ],

        'fax' => [
            'Office Fax Phone Number',
            'Business Fax',
            'Mailing Fax',
        ],

        'address' => [
            'Physical Address / Street',
            'Physical Address / City',
            'Physical Address / State Code',
            'Physical Address / Zip Code',
            'Mailing Address / Street',
            'Mailing Address / City',
            'Mailing Address / State Code',
            'Mailing Address / Zip Code',
            'Mailing Street',
            'Mailing City',
            'Mailing State Code',
            'Mailing Zip Code',
            'Mailing PO Box Street',
            'Mailing Colonia',
            'Mailing Country Code',
            'Business PO Box Street',
            'Business City',
            'Business State Code',
            'Business Zip Code',
            'Business Colonia',
            'Business Country Code',
        ],

        'contact' => [
            'Company Representative One',
            'Company Representative Two',
        ],
    ];

    /**
     * Address fields FMCSA derives rather than the carrier filing them.
     *
     * The region code alone changes more often than every real address field
     * put together, so counting these would report a carrier as having moved
     * house dozens of times. Shown on the timeline, left out of the counts.
     */
    protected const DERIVED_FIELDS = [
        'Physical Address / FMCSA Region',
        'Physical Address / County Code',
        'Physical Address / Nationality',
        'Mailing Address / County Code',
        'Mailing Address / Nationality',
        'Mexican Neighborhood / Physical',
        'Address Status',
        'Undeliverable Physical Address',
        'Undeliverable Mailing Address',
    ];

    /** Change log field -> the `carriers` column holding the same thing today. */
    protected const FORMER_VALUE_FIELDS = [
        'Email Address' => 'email_address',
        'Office Telephone Number' => 'telephone',
        'Business Telephone' => 'telephone',
        'Cell Phone Number' => 'telephone',
        'Office Fax Phone Number' => 'fax',
        'Business Fax' => 'fax',
        'Legal Name (from DOT Record)' => 'legal_name',
        'Legal Name (from MC Record)' => 'legal_name',
        'DBA Name (from DOT Record)' => 'dba_name',
        'DBA Name (from MC Record)' => 'dba_name',
        'Physical Address / Street' => 'phy_street',
        'Mailing Address / Street' => 'mailing_street',
        'Mailing Street' => 'mailing_street',
    ];

    /** A street this short matches half the country; not worth an association. */
    protected const MIN_STREET_LENGTH = 6;

    protected ?string $indexFile = null;

    /**
     * Has the index been built on this machine?
     *
     * Everything else returns empty rather than throwing when it has not, so a
     * deploy that lands before the index does degrades to "no history yet"
     * instead of a broken profile.
     */
    public function isIndexed(): bool
    {
        return $this->indexFile() !== null;
    }

    /**
     * Every contact-related change this carrier has on file, with the counts
     * the profile summarises it by.
     */
    public function contactHistory(string $dot): array
    {
        return Cache::remember(
            "carrier:change-log:contact:{$dot}",
            (int) config('carriers.change_log.cache_ttl', 900),
            fn () => $this->buildContactHistory($dot)
        );
    }

    /**
     * Values this carrier used to be reachable on and no longer is.
     *
     * Keyed by the `carriers` column the value would sit in, so the association
     * query can match a carrier's former phone number against whoever answers
     * that number today — the rename-and-carry-on pattern that a match on
     * current values alone cannot see.
     *
     * @param  array<string, string|null>  $current  The carrier's live values, which are excluded.
     * @return array<string, array<int, string>>
     */
    public function formerValues(string $dot, array $current = []): array
    {
        return Cache::remember(
            "carrier:change-log:former:{$dot}:".md5(serialize($current)),
            (int) config('carriers.change_log.cache_ttl', 900),
            fn () => $this->buildFormerValues($dot, $current)
        );
    }

    protected function buildContactHistory(string $dot): array
    {
        $records = $this->records($dot);

        $groups = array_fill_keys(array_keys(self::FIELD_GROUPS), [
            'count' => 0,
            'last_changed_at' => null,
            'fields' => [],
        ]);

        $entries = [];
        $totalChanges = count($records);

        foreach ($records as $record) {
            $field = (string) ($record['FieldName'] ?? '');
            $group = $this->groupFor($field);

            if ($group === null) {
                continue;
            }

            $derived = in_array($field, self::DERIVED_FIELDS, true);
            $changedAt = $this->parseDate($record['Change Date'] ?? null);

            $entries[] = [
                'group' => $group,
                'field' => $field,
                'category' => $record['Category'] ?? null,
                'old_value' => $this->clean($record['Old Value'] ?? null),
                'new_value' => $this->clean($record['New Value'] ?? null),
                'changed_at' => $record['Change Date'] ?? null,
                'changed_at_iso' => $changedAt,
                'changed_by' => $record['Changed By'] ?? null,
                'derived' => $derived,
            ];

            if ($derived) {
                continue;
            }

            $groups[$group]['count']++;
            $groups[$group]['fields'][$field] = ($groups[$group]['fields'][$field] ?? 0) + 1;

            if ($changedAt && $changedAt > ($groups[$group]['last_changed_at'] ?? '')) {
                $groups[$group]['last_changed_at'] = $changedAt;
            }
        }

        // Newest first, and undated records last rather than first.
        usort($entries, fn ($a, $b) => ($b['changed_at_iso'] ?? '') <=> ($a['changed_at_iso'] ?? ''));

        $limit = (int) config('carriers.change_log.timeline_limit', 500);

        foreach ($groups as $key => $group) {
            arsort($groups[$key]['fields']);
        }

        return [
            'dot_number' => $dot,
            'indexed' => $this->isIndexed(),
            'summary' => $groups,
            'contact_changes' => count($entries),
            'total_changes' => $totalChanges,
            'last_changed_at' => $entries[0]['changed_at_iso'] ?? null,
            'truncated' => count($entries) > $limit,
            'entries' => array_slice($entries, 0, $limit),
        ];
    }

    protected function buildFormerValues(string $dot, array $current): array
    {
        $live = [];

        foreach ($current as $column => $value) {
            $normalised = $this->normalise($value);

            if ($normalised !== null) {
                $live[$column][$normalised] = true;
            }
        }

        $limit = (int) config('carriers.change_log.former_values_limit', 8);
        $former = [];

        foreach ($this->records($dot) as $record) {
            $column = self::FORMER_VALUE_FIELDS[$record['FieldName'] ?? ''] ?? null;

            if ($column === null) {
                continue;
            }

            // Both sides of the change are candidates: the old value is what the
            // carrier moved off, and a new value that is not the live one is a
            // value they moved off later.
            foreach ([$record['Old Value'] ?? null, $record['New Value'] ?? null] as $value) {
                $clean = $this->clean($value);
                $normalised = $this->normalise($clean);

                if ($clean === null || $normalised === null) {
                    continue;
                }

                if (isset($live[$column][$normalised])) {
                    continue;
                }

                if (str_ends_with($column, 'street') && strlen($clean) < self::MIN_STREET_LENGTH) {
                    continue;
                }

                $former[$column][$normalised] = $clean;
            }
        }

        return array_map(
            fn (array $values) => array_slice(array_values($values), 0, $limit),
            $former
        );
    }

    /**
     * The carrier's slice of the export, decoded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function records(string $dot): array
    {
        $ranges = $this->ranges($dot);

        if (empty($ranges)) {
            return [];
        }

        $records = [];

        foreach ($ranges as [$offset, $length]) {
            $body = $this->readRange($offset, $length);

            if ($body === null) {
                continue;
            }

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                // A truncated first or last line means the index and the export
                // have drifted apart; skip it rather than reporting nonsense.
                if (is_array($decoded) && ($decoded['DOT'] ?? null) === $dot) {
                    $records[] = $decoded;
                }
            }
        }

        return $records;
    }

    /**
     * Byte ranges belonging to this DOT, found by binary search over the index.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    protected function ranges(string $dot): array
    {
        $file = $this->indexFile();

        if ($file === null || ! ctype_digit($dot)) {
            return [];
        }

        $handle = @fopen($file, 'rb');

        if (! $handle) {
            return [];
        }

        try {
            $header = $this->readHeader($handle);

            if ($header === null) {
                return [];
            }

            $needle = (int) $dot;
            $low = 0;
            $high = $header['entries'] - 1;
            $hit = null;

            while ($low <= $high) {
                $mid = intdiv($low + $high, 2);
                $entry = $this->readEntry($handle, $mid);

                if ($entry === null) {
                    return [];
                }

                if ($entry['dot'] === $needle) {
                    $hit = $mid;
                    break;
                }

                if ($entry['dot'] < $needle) {
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }

            if ($hit === null) {
                return [];
            }

            // A DOT can hold more than one run if the export was not written in
            // a single pass, and the index keeps them as neighbouring entries.
            $ranges = [];

            for ($i = $hit; $i >= 0; $i--) {
                $entry = $this->readEntry($handle, $i);

                if ($entry === null || $entry['dot'] !== $needle) {
                    break;
                }

                $ranges[] = [$entry['offset'], $entry['length']];
            }

            for ($i = $hit + 1; $i < $header['entries']; $i++) {
                $entry = $this->readEntry($handle, $i);

                if ($entry === null || $entry['dot'] !== $needle) {
                    break;
                }

                $ranges[] = [$entry['offset'], $entry['length']];
            }

            return $ranges;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{version: int, entries: int, size: int, built_at: int, etag: string}|null
     */
    protected function readHeader($handle): ?array
    {
        rewind($handle);
        $header = fread($handle, self::HEADER_BYTES);

        if ($header === false || strlen($header) < self::HEADER_BYTES) {
            return null;
        }

        if (substr($header, 0, 8) !== self::MAGIC) {
            Log::warning('Carrier change log index has an unrecognised header');

            return null;
        }

        $fields = unpack('Nversion/Nentries/Jsize/Jbuilt', substr($header, 8, 24));

        return [
            'version' => $fields['version'],
            'entries' => $fields['entries'],
            'size' => $fields['size'],
            'built_at' => $fields['built'],
            'etag' => rtrim(substr($header, 32, 64), "\0"),
        ];
    }

    /**
     * @return array{dot: int, offset: int, length: int}|null
     */
    protected function readEntry($handle, int $position): ?array
    {
        if (fseek($handle, self::HEADER_BYTES + ($position * self::ENTRY_BYTES)) !== 0) {
            return null;
        }

        $raw = fread($handle, self::ENTRY_BYTES);

        if ($raw === false || strlen($raw) < self::ENTRY_BYTES) {
            return null;
        }

        $entry = unpack('Jdot/Joffset/Nlength', $raw);

        return [
            'dot' => $entry['dot'],
            'offset' => $entry['offset'],
            'length' => $entry['length'],
        ];
    }

    /**
     * One ranged read against the export. Over the S3 API by default, or plain
     * HTTP when the object is public and no credentials are configured.
     */
    protected function readRange(int $offset, int $length): ?string
    {
        if ($length <= 0) {
            return null;
        }

        $range = 'bytes='.$offset.'-'.($offset + $length - 1);

        try {
            $path = config('carriers.change_log.path');

            if ($path) {
                $handle = @fopen($path, 'rb');

                if (! $handle) {
                    return null;
                }

                try {
                    fseek($handle, $offset);

                    return fread($handle, $length) ?: null;
                } finally {
                    fclose($handle);
                }
            }

            $url = config('carriers.change_log.url');

            if ($url) {
                $response = Http::withHeaders(['Range' => $range])->timeout(15)->get($url);

                return $response->successful() ? $response->body() : null;
            }

            $disk = config('carriers.change_log.disk', 's3');
            $client = Storage::disk($disk)->getClient();

            $result = $client->getObject([
                'Bucket' => config("filesystems.disks.{$disk}.bucket"),
                'Key' => config('carriers.change_log.key'),
                'Range' => $range,
            ]);

            return (string) $result['Body'];
        } catch (\Throwable $e) {
            Log::error('Carrier change log range read failed', [
                'range' => $range,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function indexFile(): ?string
    {
        if ($this->indexFile !== null) {
            return $this->indexFile;
        }

        $path = storage_path('app/'.ltrim((string) config('carriers.change_log.index_path'), '/'));

        return $this->indexFile = is_readable($path) ? $path : null;
    }

    protected function groupFor(string $field): ?string
    {
        foreach (self::FIELD_GROUPS as $group => $fields) {
            if (in_array($field, $fields, true)) {
                return $group;
            }
        }

        if (in_array($field, self::DERIVED_FIELDS, true)) {
            return 'address';
        }

        return null;
    }

    /** FMCSA writes "07/18/2026 11:04:49 AM"; sortable ISO out. */
    protected function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $parsed = \DateTime::createFromFormat('m/d/Y h:i:s A', trim($value))
            ?: \DateTime::createFromFormat('m/d/Y', trim($value));

        return $parsed ? $parsed->format('Y-m-d H:i:s') : null;
    }

    /** Blank, whitespace and the literal string NULL all mean "not set". */
    protected function clean($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '' || strcasecmp($trimmed, 'null') === 0) {
            return null;
        }

        return $trimmed;
    }

    /** Comparison form: case and punctuation differences are not real changes. */
    protected function normalise($value): ?string
    {
        $clean = $this->clean($value);

        if ($clean === null) {
            return null;
        }

        $normalised = preg_replace('/[^a-z0-9]/', '', strtolower($clean));

        return $normalised === '' ? null : $normalised;
    }
}
