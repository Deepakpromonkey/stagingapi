<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * National carrier benchmarks, read-only from the request path.
 *
 * Computing these takes minutes over the full carrier population, so they are
 * refreshed out-of-band by `carrier:refresh-benchmarks` and only ever read
 * here. Until that command has run, the measured defaults below are used —
 * a slightly stale benchmark is worth far more than a request that hangs.
 */
final class CarrierBenchmarks
{
    public const CACHE_KEY = 'carrier:benchmarks';

    /**
     * Measured 2026-08-18 over the full population.
     *
     * The two ratio bounds are null when unknown: the risk factors skip the
     * check on null, whereas a zero upper bound would flag every carrier.
     */
    public const DEFAULTS = [
        'natl_vehicle_oos' => 0.2494,
        'pum_lo' => 0.0000070684,
        'pum_hi' => 1.0,
        'im_lo' => null,
        'im_hi' => null,
    ];

    /** The five BASICs, keyed by the column prefix they share. */
    public const SMS_BASICS = ['unsafe_driv', 'hos_driv', 'driv_fit', 'contr_subst', 'veh_maint'];

    public const SMS_CACHE_KEY = 'carrier:sms_cuts';

    /**
     * Measured cut-points, used until the refresh command has run. A zero cut
     * is real for BASICs where most carriers score nothing; measureBand()
     * refuses to band a zero measure, so it never flags everybody.
     */
    public const SMS_DEFAULTS = [
        'unsafe_driv' => [50 => 0.0, 75 => 2.50, 90 => 8.00],
        'hos_driv' => [50 => 0.0, 75 => 0.51, 90 => 2.80],
        'driv_fit' => [50 => 0.0, 75 => 0.12, 90 => 1.79],
        'contr_subst' => [50 => 0.0, 75 => 0.0, 90 => 0.0],
        'veh_maint' => [50 => 3.60, 75 => 8.66, 90 => 15.00],
    ];

    public static function smsCuts(): array
    {
        $cached = Cache::get(self::SMS_CACHE_KEY);

        return is_array($cached) && $cached !== [] ? $cached : self::SMS_DEFAULTS;
    }

    public static function all(): array
    {
        return Cache::get(self::CACHE_KEY, []) + self::DEFAULTS;
    }
}
