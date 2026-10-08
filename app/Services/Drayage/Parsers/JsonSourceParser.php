<?php

namespace App\Services\Drayage\Parsers;

use App\Exceptions\DrayageException;
use App\Services\Drayage\DrayageFields;

/**
 * The LoadMatch scrape, as a JSON array of carrier objects or as JSONL (one
 * object per line).
 *
 * A record's keys may be the dictionary keys (company_name), the CSV labels
 * ("Company"), or a carrier document nested by section ({"identity": {...}}),
 * which is flattened one level. Values may be strings, numbers, booleans or
 * arrays; the normalizer types them the same way it types CSV cells. Keys it
 * cannot place are kept under `extra` and listed in the report as unknown.
 *
 * A JSON array is split into objects as it is read, never decoded whole, so
 * a large scrape costs no more memory than a CSV of the same size. Row
 * numbers are 1-based record positions.
 */
class JsonSourceParser implements SourceParser
{
    private const CHUNK = 65536;

    private array $seen = [];

    private array $unknown = [];

    public function rows(string $path): \Generator
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw DrayageException::unreadable('The file could not be opened.');
        }

        try {
            $first = $this->firstSignificantByte($handle);

            if ($first === null) {
                throw DrayageException::unreadable('The file is empty.');
            }

            $records = $first === '[' ? $this->arrayRecords($handle) : $this->lineRecords($handle);

            $row = 0;

            foreach ($records as $json) {
                $row++;

                if (! mb_check_encoding($json, 'UTF-8')) {
                    yield ['row' => $row, 'error' => 'The record is not valid UTF-8.'];

                    continue;
                }

                $record = json_decode($json, true);

                if (! is_array($record) || array_is_list($record)) {
                    yield ['row' => $row, 'error' => 'The record is not a JSON object.'];

                    continue;
                }

                yield ['row' => $row, ...$this->mapRecord($record), 'error' => null];
            }
        } finally {
            fclose($handle);
        }

        RequiredColumns::assert(array_keys($this->seen));
    }

    public function headerReport(): array
    {
        return [
            'missing' => array_values(array_diff(DrayageFields::keys(), array_keys($this->seen))),
            'unknown' => array_keys($this->unknown),
        ];
    }

    private function mapRecord(array $record): array
    {
        $map = DrayageFields::headerMap();
        $values = [];
        $extra = [];

        // A document nested by section is flattened one level first.
        foreach ($record as $name => $value) {
            if ((in_array($name, DrayageFields::SECTIONS, true) || $name === 'links') && is_array($value) && ! array_is_list($value)) {
                unset($record[$name]);

                if ($name === 'extra') {
                    $extra = array_merge($extra, $value);

                    continue;
                }

                foreach ($value as $childName => $childValue) {
                    $record[$childName] ??= $childValue;
                }
            }
        }

        foreach ($record as $name => $value) {
            $normalized = DrayageFields::normalizeHeader((string) $name);
            $key = $map[$normalized] ?? null;

            if (in_array($normalized, DrayageFields::DROPPED, true)) {
                continue;
            }

            if ($key !== null) {
                $values[$key] = $value;
                $this->seen[$key] = true;
            } elseif ($value !== null && $value !== '' && $value !== []) {
                $extra[(string) $name] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->unknown[(string) $name] = true;
            }
        }

        return ['values' => $values, 'extra' => $extra];
    }

    /**
     * @return \Generator<int, string>
     */
    private function lineRecords($handle): \Generator
    {
        rewind($handle);

        while (($line = fgets($handle)) !== false) {
            $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));

            if ($line !== '') {
                yield $line;
            }
        }
    }

    /**
     * Each top-level object of a JSON array, as its own JSON string. Tracks
     * nesting depth outside string literals; that is all a splitter needs.
     *
     * @return \Generator<int, string>
     */
    private function arrayRecords($handle): \Generator
    {
        rewind($handle);

        $depth = 0;
        $inString = false;
        $escaped = false;
        $buffer = '';

        while (($chunk = fread($handle, self::CHUNK)) !== false && $chunk !== '') {
            $length = strlen($chunk);

            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];

                if ($depth >= 2) {
                    $buffer .= $char;
                }

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{' || $char === '[') {
                    $depth++;

                    if ($depth === 2) {
                        $buffer = $char;
                    }
                } elseif ($char === '}' || $char === ']') {
                    $depth--;

                    if ($depth === 1) {
                        yield $buffer;
                        $buffer = '';
                    }
                }
            }
        }

        if ($depth !== 0) {
            throw DrayageException::unreadable('The JSON file ends before its array closes.');
        }
    }

    private function firstSignificantByte($handle): ?string
    {
        while (($char = fgetc($handle)) !== false) {
            if (! ctype_space($char) && $char !== "\xEF" && $char !== "\xBB" && $char !== "\xBF") {
                return $char;
            }
        }

        return null;
    }
}
