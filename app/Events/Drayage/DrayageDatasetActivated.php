<?php

namespace App\Events\Drayage;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A drayage dataset went live: an import finished, or an administrator
 * rolled back or forward. `reason` says which.
 */
class DrayageDatasetActivated
{
    use Dispatchable;

    public function __construct(
        public readonly string $datasetId,
        public readonly ?string $previousDatasetId,
        public readonly ?array $actor,
        public readonly string $reason,
    ) {}
}
