<?php

namespace App\Pay\Http;

use App\Models\User;
use App\Pay\Models\Payment;
use Throwable;

/**
 * DT Pay's permissions, as config/rbac.php defines them:
 *
 *   create-fund-payment                     start, fund, hold, dispute
 *   release-conditional-payment-capped      release up to the user's limit
 *   release-conditional-payment-unlimited   release any amount
 *   refund-cancel-payment                   refund a held payment, cancel an unpaid one
 */
final class Permissions
{
    public static function can(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (Throwable) {
            return false;   // permission not seeded on this install
        }
    }

    public static function canRelease(?User $user, Payment $payment): bool
    {
        if (self::can($user, 'release-conditional-payment-unlimited')) {
            return true;
        }

        if (! self::can($user, 'release-conditional-payment-capped')) {
            return false;
        }

        $limit = $user->paymentReleaseLimit();

        // A capped role with no limit set releases nothing rather than anything.
        return $limit !== null && $payment->amount_cents <= (int) round(((float) $limit) * 100);
    }
}
