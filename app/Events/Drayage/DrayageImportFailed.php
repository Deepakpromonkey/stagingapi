<?php

namespace App\Events\Drayage;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A drayage import ended without activating anything. The previous dataset
 * is still live; the import's report says why.
 */
class DrayageImportFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $importId,
        public readonly string $reason,
        public readonly ?array $actor,
    ) {}
}
