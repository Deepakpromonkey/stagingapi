<?php

namespace App\Pay\Stripe;

use Illuminate\Support\Facades\Log;

/**
 * Wraps a StripeClient (or any of its services) so its calls tolerate
 * Stripe's informational notices.
 *
 * stripe-php raises a response's "stripe-notice" header (for example, a
 * recommendation to move to a newer API) as a PHP warning, and Laravel turns
 * warnings into exceptions - so a notice would fail a request Stripe had
 * actually completed. Here such notices are logged instead; any other
 * warning still surfaces as usual.
 *
 *   $quiet->customers->create([...])
 *   $quiet->v2->core->accounts->create([...])
 */
final class QuietStripe
{
    public function __construct(private object $target)
    {
    }

    /** A nested service: $client->v2, ->core, ->accounts, ... */
    public function __get(string $name): self
    {
        return new self($this->target->{$name});
    }

    public function __call(string $method, array $args)
    {
        return self::quietly(fn () => $this->target->{$method}(...$args));
    }

    public static function quietly(callable $call)
    {
        set_error_handler(function (int $no, string $message, string $file = ''): bool {
            if ($no === E_USER_WARNING && str_contains($file, 'stripe-php')) {
                Log::info('[DTPay] Stripe notice: '.$message);

                return true;
            }

            return false;
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
