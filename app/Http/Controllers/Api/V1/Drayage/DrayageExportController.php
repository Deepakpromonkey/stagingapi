<?php

namespace App\Http\Controllers\Api\V1\Drayage;

use App\Events\Drayage\DrayageExportCreated;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Drayage\ExportDrayageCarriersRequest;
use App\Services\Drayage\DrayageAudit;
use App\Services\Drayage\DrayageDirectoryService;
use App\Services\Drayage\DrayageFields;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /drayage/export - the carriers matching the list endpoint's filters,
 * as a CSV with the export's own column labels, so a file exported here
 * re-imports cleanly.
 *
 * Exports carry emails and phone numbers, so this sits behind its own
 * permission and a tight rate limit, and every one is audited with who
 * asked, which filters and how many rows.
 */
class DrayageExportController extends BaseController
{
    private const CHUNK = 250;

    public function __construct(
        private DrayageDirectoryService $directory,
        private DrayageAudit $audit,
    ) {}

    public function export(ExportDrayageCarriersRequest $request)
    {
        $query = $request->drayageQuery();

        // Every column unless fields= narrows it - and then exactly those, in
        // that order (the query object adds carrier_key, which is the API's
        // handle rather than an export column).
        $fields = $request->filled('fields')
            ? array_values(array_unique(array_map('trim', explode(',', $request->query('fields')))))
            : DrayageFields::keys();

        $rows = $this->directory->matching($query);
        $count = count($rows);

        if ($count > config('drayage.export_max_rows')) {
            return $this->error('That export would have '.$count.' rows, above the limit of '.config('drayage.export_max_rows').'. Narrow the filters.', null, 422);
        }

        $datasetId = $this->directory->datasetId();
        $actor = DrayageAudit::actor($request->user());

        $this->audit->record('export.created', $actor, $request->ip(), [
            'dataset_id' => $datasetId,
            'format' => 'csv',
            'filters' => $query->describeFilters(),
            'fields' => count($fields) === count(DrayageFields::keys()) ? 'all' : $fields,
            'row_count' => $count,
        ]);

        event(new DrayageExportCreated($actor, DrayageAudit::scrub($query->describeFilters()), $count, 'csv', $datasetId));

        $filename = 'drayage_carriers_'.now()->format('Y-m-d').'_'.$count.'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$filename}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $labels = array_map(fn ($f) => DrayageFields::get($f)['label'] ?? ucfirst(str_replace('_', ' ', $f)), $fields);
        $types = array_map(fn ($f) => DrayageFields::get($f)['type'] ?? 'text', $fields);

        return new StreamedResponse(function () use ($rows, $fields, $labels, $types) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $labels, escape: '');

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                foreach ($this->directory->project($chunk, $fields) as $item) {
                    $line = [];

                    foreach ($fields as $i => $field) {
                        $line[] = self::cell($item[$field] ?? null, $types[$i]);
                    }

                    fputcsv($out, $line, escape: '');
                }
            }

            fclose($out);
        }, 200, $headers);
    }

    /**
     * One CSV cell: booleans as Yes/No/blank, lists joined with "; ", and
     * anything a spreadsheet would run as a formula defused with a leading
     * apostrophe.
     */
    public static function cell(mixed $value, string $type): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode('; ', $value),
            default => (string) $value,
        };

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }
}
