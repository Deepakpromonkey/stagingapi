<?php

namespace App\Pay;

use RuntimeException;

/** A DT Pay action that the rules do not allow; its message is shown to the user. */
class PaymentRuleViolation extends RuntimeException
{
}
