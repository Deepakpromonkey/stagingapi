<?php

namespace App\Exceptions;

use Exception;

/**
 * Anything the drayage directory refuses: a file it cannot import, a dataset
 * that is not there, an import already running.
 *
 * Carries the HTTP status the API should answer with; bootstrap/app.php turns
 * it into the standard error envelope, the same way it does BillingException.
 */
class DrayageException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?array $errors = null
    ) {
        parent::__construct($message);
    }

    public static function noDataset(): self
    {
        return new self('No drayage dataset is live yet. An administrator needs to import one.', 404);
    }

    public static function importRunning(): self
    {
        return new self('Another drayage import is running. Try again when it finishes.', 409);
    }

    public static function unreadable(string $reason): self
    {
        return new self($reason, 422);
    }
}
