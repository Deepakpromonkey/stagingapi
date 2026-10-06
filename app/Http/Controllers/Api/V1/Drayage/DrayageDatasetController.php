<?php

namespace App\Http\Controllers\Api\V1\Drayage;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\Drayage\DrayageAudit;
use App\Services\Drayage\DrayageImporter;
use App\Services\Drayage\DrayageStorage;
use Illuminate\Http\Request;

/**
 * Drayage dataset versions - DollarTraq staff only.
 *
 * Activating an older dataset is the rollback: the pointer moves, readers
 * follow on their next request, no restart. The live dataset cannot be
 * deleted; activate another one first.
 */
class DrayageDatasetController extends BaseController
{
    public function __construct(
        private DrayageImporter $importer,
        private DrayageStorage $storage,
    ) {}

    public function index()
    {
        $current = $this->storage->current();

        $datasets = array_map(fn (array $manifest) => $manifest + [
            'is_current' => $manifest['dataset_id'] === ($current['dataset_id'] ?? null),
            'bytes' => $this->storage->datasetBytes($manifest['dataset_id']),
        ], $this->storage->datasets());

        return $this->success([
            'current' => $current,
            'datasets' => $datasets,
            'retention' => config('drayage.retention'),
        ], 'Drayage datasets retrieved successfully.');
    }

    public function activate(Request $request, string $datasetId)
    {
        $pointer = $this->importer->activate($datasetId, DrayageAudit::actor($request->user()), $request->ip(), 'manual');

        return $this->success(['current' => $pointer], 'Dataset activated.');
    }

    public function destroy(Request $request, string $datasetId)
    {
        $this->importer->delete($datasetId, DrayageAudit::actor($request->user()), $request->ip());

        return $this->success(null, 'Dataset deleted.');
    }
}
