<?php

namespace App\Pay\Stripe;

use RuntimeException;

/** DT Pay cannot reach Stripe as configured (no key, or a live key not allowed). */
class PaymentsUnavailable extends RuntimeException
{
}
