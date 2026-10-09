<?php

namespace App\Events\Drayage;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone exported drayage carriers. Exports carry contact details, so who,
 * with which filters and how many rows is worth knowing.
 */
class DrayageExportCreated
{
    use Dispatchable;

    public function __construct(
        public readonly ?array $actor,
        public readonly array $filters,
        public readonly int $rowCount,
        public readonly string $format,
        public readonly string $datasetId,
    ) {}
}
