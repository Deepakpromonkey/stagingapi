<?php

namespace App\Support;

/**
 * VIN helpers.
 *
 * The important idea here is the *pattern*. A 17-character VIN carries the
 * vehicle's identity in positions 1-8 (world manufacturer identifier plus the
 * vehicle descriptor) and position 10 (the model year code). Position 9 is a
 * check digit and positions 11-17 are the assembly plant and serial number —
 * they identify the individual unit and say nothing about year, make or model.
 *
 * So every truck a carrier bought in the same model year and configuration
 * shares one pattern, and NHTSA returns the identical decode for all of them:
 *
 *   1FUJGLDR*C        -> 2012 FREIGHTLINER Cascadia (Truck-Tractor)
 *   1FUJGLDR9CLBP8834 -> 2012 FREIGHTLINER Cascadia (Truck-Tractor)
 *   1FUJGLDR2CLBS9911 -> 2012 FREIGHTLINER Cascadia (Truck-Tractor)
 *
 * That is what makes caching viable. The inspections table holds tens of
 * millions of VIN values; it holds only a few tens of thousands of patterns,
 * which is a table small enough to keep entirely in memory.
 */
class Vin
{
    /**
     * Characters I, O and Q are never used in a VIN — they would be confused
     * with 1 and 0 — so their presence means the value is not a real VIN.
     */
    public const VALID = '/^[A-HJ-NPR-Z0-9]{17}$/';

    /**
     * Uppercase and drop separators. The FMCSA feed is hand-keyed in places
     * and VINs arrive with stray spaces and hyphens.
     */
    public static function normalize(?string $vin): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $vin)));
    }

    public static function isValid(?string $vin): bool
    {
        $vin = self::normalize($vin);

        // The feed uses runs of zeros as a placeholder for "no VIN recorded".
        if (preg_match('/^0+$/', $vin)) {
            return false;
        }

        return (bool) preg_match(self::VALID, $vin);
    }

    /**
     * The 9-character cache key: positions 1-8 followed by position 10.
     */
    public static function pattern(?string $vin): ?string
    {
        $vin = self::normalize($vin);

        if (! self::isValid($vin)) {
            return null;
        }

        return substr($vin, 0, 8).substr($vin, 9, 1);
    }

    /**
     * The same pattern in the form vPIC's decoder expects: the missing
     * check digit is written as a wildcard, and everything from position 11
     * on is simply left off.
     */
    public static function toPartialVin(string $pattern): string
    {
        return substr($pattern, 0, 8).'*'.substr($pattern, 8, 1);
    }

    /**
     * Map a list of raw VINs to their distinct patterns.
     *
     * @param  iterable<string|null>  $vins
     * @return array<int, string>
     */
    public static function patterns(iterable $vins): array
    {
        $patterns = [];

        foreach ($vins as $vin) {
            if ($pattern = self::pattern($vin)) {
                $patterns[$pattern] = true;
            }
        }

        return array_keys($patterns);
    }
}
