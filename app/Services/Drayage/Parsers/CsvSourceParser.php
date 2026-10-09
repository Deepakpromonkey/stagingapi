<?php

namespace App\Services\Drayage\Parsers;

use App\Exceptions\DrayageException;
use App\Services\Drayage\DrayageFields;

/**
 * The Drayage Carrier Finder CSV export: UTF-8 (BOM optional), RFC 4180
 * quoting, one header row of human labels.
 *
 * Row numbers are the spreadsheet's - the header is row 1 - so a rejected
 * row in the report can be found by opening the file.
 */
class CsvSourceParser implements SourceParser
{
    private array $report = ['missing' => [], 'unknown' => []];

    public function rows(string $path): \Generator
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw DrayageException::unreadable('The file could not be opened.');
        }

        try {
            $header = $this->read($handle);

            if ($header === false || $header === [null]) {
                throw DrayageException::unreadable('The file is empty.');
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

            if (! mb_check_encoding(implode('', $header), 'UTF-8')) {
                throw DrayageException::unreadable('The file is not UTF-8 encoded.');
            }

            $columns = $this->mapColumns($header);
            $expected = count($header);
            $row = 1;

            while (($cells = $this->read($handle)) !== false) {
                $row++;

                if ($cells === [null]) {
                    continue;
                }

                if (count($cells) !== $expected) {
                    yield ['row' => $row, 'error' => "Expected {$expected} columns, found ".count($cells).'.'];

                    continue;
                }

                if (! mb_check_encoding(implode("\x1F", $cells), 'UTF-8')) {
                    yield ['row' => $row, 'error' => 'The row is not valid UTF-8.'];

                    continue;
                }

                $values = [];
                $extra = [];

                foreach ($cells as $i => $cell) {
                    [$kind, $name] = $columns[$i];

                    if ($kind === 'key') {
                        $values[$name] = $cell;
                    } elseif ($kind === 'extra' && $cell !== '') {
                        $extra[$name] = $cell;
                    }
                }

                yield ['row' => $row, 'values' => $values, 'extra' => $extra, 'error' => null];
            }
        } finally {
            fclose($handle);
        }
    }

    public function headerReport(): array
    {
        return $this->report;
    }

    /**
     * @return array<int, array{0: string, 1: string}> column index => [key|extra|skip, name]
     */
    private function mapColumns(array $header): array
    {
        $map = DrayageFields::headerMap();
        $columns = [];
        $mapped = [];
        $unknown = [];

        foreach ($header as $i => $label) {
            $label = trim((string) $label);
            $normalized = DrayageFields::normalizeHeader($label);

            if ($normalized === '' || in_array($normalized, DrayageFields::DROPPED, true)) {
                $columns[$i] = ['skip', ''];

                continue;
            }

            $key = $map[$normalized] ?? null;

            // A second column claiming a key already mapped is kept as extra
            // rather than silently overwriting the first.
            if ($key !== null && ! isset($mapped[$key])) {
                $columns[$i] = ['key', $key];
                $mapped[$key] = true;
            } else {
                $columns[$i] = ['extra', $label];
                $unknown[] = $label;
            }
        }

        RequiredColumns::assert(array_keys($mapped));

        $this->report = [
            'missing' => array_values(array_diff(DrayageFields::keys(), array_keys($mapped))),
            'unknown' => $unknown,
        ];

        return $columns;
    }

    /**
     * fgetcsv with an empty escape character: RFC 4180 escapes a quote by
     * doubling it, and PHP's default backslash escape would mangle any value
     * that happens to end in one.
     */
    private function read($handle): array|false
    {
        return fgetcsv($handle, null, ',', '"', '');
    }
}
