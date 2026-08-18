<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Value decoding for the FMCSA carrier database on EC2 (`external_db`).
 *
 * The Motus-format load changed how several columns encode their values, and
 * the two encodings still coexist in places, so every reader goes through here
 * rather than comparing against a literal:
 *
 *   authority status   'ACTIVE' / 'INACTIVE'  ->  'A' / 'I' / 'N'
 *   boolean flags      'Y' / 'X' / 'N'        ->  'true' / 'false'
 *   authority type     long descriptions only ->  long *and* short forms
 *                                                 ('PROPERTY BROKER' | 'BROKER')
 *
 * Dates arrive in three shapes depending on the table: '24-APR-24' (carriers,
 * inspections, authority history), '09/23/2004' (insurance filings) and plain
 * ISO. self::date() takes all three.
 */
final class Fmcsa
{
    /** FMCSA fleet-size bands, keyed by carrier_details.fleetsize. */
    public const FLEET_SIZE = [
        'A' => '1',        'B' => '2-3',      'C' => '4-6',      'D' => '7-8',
        'E' => '9-11',     'F' => '12-14',    'G' => '15-17',    'H' => '18-19',
        'I' => '20-23',    'J' => '24-28',    'K' => '29-32',    'L' => '33-38',
        'M' => '39-44',    'N' => '45-55',    'O' => '56-75',    'P' => '76-100',
        'Q' => '101-200',  'R' => '201-300',  'S' => '301-400',  'T' => '401-550',
        'U' => '551-999',  'V' => '1000-2000', 'W' => '2001-3000', 'X' => '3001-4000',
        'Y' => '4001-5000', 'Z' => 'OVER 5000',
    ];

    /**
     * carrier_authority_history.op_auth_type carries both the long FMCSA
     * description and a short form, and which one a row uses varies by vintage.
     */
    public const AUTHORITY_TYPES = [
        'common' => ['MOTOR PROPERTY COMMON CARRIER', 'COMMON'],
        'contract' => ['MOTOR PROPERTY CONTRACT CARRIER', 'CONTRACT'],
        'broker' => ['PROPERTY BROKER', 'BROKER'],
    ];

    /** Truthy encodings seen across the FMCSA feeds, old and new. */
    private const TRUTHY = ['TRUE', 'Y', 'YES', 'X', '1', 'A', 'ACTIVE'];

    /**
     * Is an operating-authority status active?
     *
     * New load stores 'A' / 'I' / 'N'; the pre-Motus load stored 'ACTIVE'.
     */
    public static function isActive(mixed $status): bool
    {
        $status = strtoupper(trim((string) $status));

        return $status === 'A' || $status === 'ACTIVE';
    }

    /**
     * Is a yes/no column set?
     *
     * Covers 'true'/'false' (current), 'Y'/'X'/'1' (legacy) and real booleans.
     */
    public static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        return in_array(strtoupper(trim((string) $value)), self::TRUTHY, true);
    }

    /**
     * Is an insurance "on file" column populated?
     *
     * carrier_authorities encodes these three inconsistently: `bipd_file` is a
     * coverage amount in thousands, zero-padded ('00000' means nothing on
     * file), while `cargo_file` and `bond_file` are plain 'Y' / 'N'. None of
     * those values is empty(), so the obvious check reads all three as
     * satisfied for every carrier in the table.
     */
    public static function onFile(mixed $value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        return is_numeric($value)
            ? (float) $value > 0
            : self::flag($value);
    }

    /**
     * Parse any of the date shapes the feed uses. Returns null rather than
     * throwing — a good third of these columns are blank.
     */
    public static function date(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            // '24-APR-24' — two-digit year, so anchor the century by hand.
            // The feed goes back to the 1970s, hence the pivot on today's year.
            if (preg_match('/^(\d{2})-([A-Z]{3})-(\d{2})$/', strtoupper($value), $m)) {
                $century = $m[3] > date('y') ? '19' : '20';

                return Carbon::createFromFormat('d-M-Y', "{$m[1]}-{$m[2]}-{$century}{$m[3]}")
                    ->startOfDay();
            }

            // '09/23/2004' — US order, which Carbon::parse already assumes.
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Sortable key for a feed date, for max()/sortBy over raw strings. */
    public static function dateKey(mixed $value): int
    {
        return self::date($value)?->getTimestamp() ?? 0;
    }

    public static function fleetSize(?string $code): ?string
    {
        return self::FLEET_SIZE[strtoupper(trim((string) $code))] ?? null;
    }

    public static function safetyRating(?string $code): string
    {
        return match (strtoupper(trim((string) $code))) {
            'S', 'SATISFACTORY' => 'Satisfactory',
            'C', 'CONDITIONAL' => 'Conditional',
            'U', 'UNSATISFACTORY' => 'Unsatisfactory',
            default => 'Not Rated',
        };
    }

    /** True when a safety rating is Unsatisfactory or Conditional. */
    public static function isAdverseSafetyRating(?string $code): bool
    {
        return in_array(self::safetyRating($code), ['Unsatisfactory', 'Conditional'], true);
    }

    /** Every accepted spelling of an authority type, for whereIn/filter. */
    public static function authorityType(string $key): array
    {
        return self::AUTHORITY_TYPES[$key] ?? [];
    }
}
