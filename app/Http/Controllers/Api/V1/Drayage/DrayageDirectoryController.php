<?php

namespace App\Http\Controllers\Api\V1\Drayage;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\Drayage\DrayageDirectoryService;
use App\Services\Drayage\DrayageFields;

/**
 * What the frontend needs to build drayage filters without hardcoding them:
 * the field dictionary, the live dataset's option lists and bounds, and its
 * headline numbers.
 */
class DrayageDirectoryController extends BaseController
{
    public function __construct(private DrayageDirectoryService $directory) {}

    public function fields()
    {
        return $this->success(
            DrayageFields::describe() + ['source' => config('drayage.source_label')],
            'Drayage fields retrieved successfully.'
        );
    }

    public function facets()
    {
        $current = $this->directory->current();

        return $this->success([
            'facets' => $this->directory->facets(),
            'dataset' => [
                'dataset_id' => $current['dataset_id'],
                'activated_at' => $current['activated_at'],
            ],
            'source' => config('drayage.source_label'),
        ], 'Drayage facets retrieved successfully.');
    }

    public function stats()
    {
        return $this->success($this->directory->stats(), 'Drayage stats retrieved successfully.');
    }
}
