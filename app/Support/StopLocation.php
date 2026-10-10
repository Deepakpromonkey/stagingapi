<?php

namespace App\Support;

/**
 * Whether a stop has a place on the map the driver side can use.
 *
 * The driver app draws every stop from shipment_stops.latitude / longitude,
 * and the driver API only lets a driver mark "Arrived" within 500 m of them.
 * Both treat anything other than a latitude from -90 to 90 and a longitude
 * from -180 to 180 as "no location" - this is that same rule, so a stop the
 * driver side would choke on is refused when the trip sheet is saved rather
 * than found by a driver standing at the dock.
 */
final class StopLocation
{
    public static function isLatitude(mixed $value): bool
    {
        $number = self::number($value);

        return $number !== null && $number >= -90 && $number <= 90;
    }

    public static function isLongitude(mixed $value): bool
    {
        $number = self::number($value);

        return $number !== null && $number >= -180 && $number <= 180;
    }

    /**
     * A JSON number, or a numeric string the way an API client may send one.
     * Null for anything else - booleans, "", "abc", and the INF json_decode()
     * makes of an absurdly large number.
     */
    public static function number(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);

            if (! is_numeric($value)) {
                return null;
            }

            $value = (float) $value;
        }

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) ? $value : null;
    }
}
