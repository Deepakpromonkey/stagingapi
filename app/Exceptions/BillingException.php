<?php

namespace App\Exceptions;

use Exception;

/**
 * Anything that stops a subscription going through — a plan that has no price
 * configured, a Stripe call that failed, a company with no customer record.
 *
 * Carries the HTTP status the API should answer with; bootstrap/app.php turns
 * it into the standard error envelope.
 */
class BillingException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?array $errors = null
    ) {
        parent::__construct($message);
    }

    public static function stripeUnavailable(): self
    {
        return new self('Billing is unavailable right now. Please try again shortly.', 503);
    }

    public static function stripeFailed(string $message = 'We could not reach the payment provider. Please try again.'): self
    {
        return new self($message, 502);
    }
}
