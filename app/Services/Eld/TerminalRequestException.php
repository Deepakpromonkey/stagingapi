<?php

namespace App\Services\Eld;

use RuntimeException;

/**
 * A non-2xx from Terminal. Carries the status and body so the caller can log
 * something useful instead of "request failed".
 */
class TerminalRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = ''
    ) {
        parent::__construct($message);
    }

    public function context(): array
    {
        return [
            'status' => $this->status,

            // Enough to identify the failure without pasting a fleet into the log.
            'body' => mb_substr($this->body, 0, 1000),
        ];
    }
}
