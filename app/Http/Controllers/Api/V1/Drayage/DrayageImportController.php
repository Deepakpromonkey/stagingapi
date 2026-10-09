<?php

namespace App\Http\Controllers\Api\V1\Drayage;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Drayage\StoreDrayageImportRequest;
use App\Jobs\ImportDrayageDataset;
use App\Services\Drayage\DrayageAudit;
use App\Services\Drayage\DrayageImporter;
use App\Services\Drayage\DrayageStorage;

/**
 * Drayage imports - DollarTraq staff only (manage-drayage-directory).
 *
 * An upload is stored and queued, and answered with 202 and its import id
 * straight away; the worker does the import and the report fills in as it
 * runs. GET /drayage/imports/{id} is how the caller finds out how it went.
 */
class DrayageImportController extends BaseController
{
    /** Report sections too long for a list view; GET one import for them. */
    private const DETAIL_ONLY = ['rejected_rows', 'merges', 'field_coverage'];

    public function __construct(
        private DrayageImporter $importer,
        private DrayageStorage $storage,
    ) {}

    public function store(StoreDrayageImportRequest $request)
    {
        $file = $request->file('file');

        $import = $this->importer->queue(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            $request->sourceFormat(),
            DrayageAudit::actor($request->user()),
            $request->ip(),
        );

        ImportDrayageDataset::dispatch($import['import_id']);

        return $this->success([
            'import_id' => $import['import_id'],
            'status' => $import['status'],
            'import' => $import,
        ], 'Import queued. Check GET /drayage/imports/'.$import['import_id'].' for progress.', 202);
    }

    public function index()
    {
        $imports = array_map(function (array $import) {
            if (is_array($import['report'] ?? null)) {
                foreach (self::DETAIL_ONLY as $key) {
                    unset($import['report'][$key]);
                }
                unset($import['report']['warnings']['samples']);
            }

            return $import;
        }, array_slice($this->storage->imports(), 0, 100));

        return $this->success(['imports' => $imports], 'Drayage imports retrieved successfully.');
    }

    public function show(string $importId)
    {
        $import = $this->storage->import($importId);

        if (! $import) {
            return $this->error('Import not found.', null, 404);
        }

        return $this->success(['import' => $import], 'Drayage import retrieved successfully.');
    }
}
