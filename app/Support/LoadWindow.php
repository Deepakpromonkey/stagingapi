<?php

namespace App\Support;

use ArrayAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * When a load holds its driver: from the earliest time on it to the latest.
 *
 * A load's times are kept the way the booking forms send them - three plain
 * strings per time: a date, a time and a timezone label ("Central", in older
 * rows "(UTC-06:00) Central"). They are read here the way the driver API reads
 * them for the app (its ShipmentStop::zoneFor()): the label names an IANA
 * zone, so daylight saving comes out right.
 *
 * A date with no time could be any time that day, so it holds the whole day.
 * A time whose label names no zone is read in the zone of another time on the
 * same load - the trip sheet's pickup timezone is optional - and in UTC when
 * no time on the load has one.
 */
final class LoadWindow
{
    private const ZONES = [
        'eastern' => 'America/New_York',
        'central' => 'America/Chicago',
        'mountain' => 'America/Denver',
        'pacific' => 'America/Los_Angeles',
        'arizona' => 'America/Phoenix',
        'india' => 'Asia/Kolkata',
        'ist' => 'Asia/Kolkata',
    ];

    /** @var array<string, string>|null lower-cased IANA id => IANA id */
    private static ?array $ianaIds = null;

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        private readonly bool $startHasTime,
        private readonly bool $endHasTime,
    ) {}

    /**
     * From a load's stops - as posted to the stops endpoint, or saved
     * ShipmentStop rows. Null when no stop has a date.
     *
     * Both windows of every stop count. The trip sheet fills the start window
     * on the pickup and the end window on every later stop, but a stop that
     * has both holds the driver for both.
     *
     * @param  iterable<mixed>  $stops
     */
    public static function fromStops(iterable $stops): ?self
    {
        $times = [];

        foreach ($stops as $stop) {
            if (! is_array($stop) && ! $stop instanceof ArrayAccess) {
                continue;
            }

            foreach (['start', 'end'] as $window) {
                $times[] = [
                    $stop["{$window}_date"] ?? null,
                    $stop["{$window}_time"] ?? null,
                    $stop["{$window}_timezone"] ?? null,
                ];
            }
        }

        return self::spanning($times);
    }

    /**
     * From a load's own pickup and delivery fields, as POST /shipments takes
     * them: an ELD load's only times, and a trip sheet's first and last stop.
     * Null when neither has a date.
     *
     * @param  array<string, mixed>|ArrayAccess<string, mixed>  $load
     */
    public static function fromPickupAndDelivery(array|ArrayAccess $load): ?self
    {
        return self::spanning([
            [$load['pickup_date'] ?? null, $load['pickup_time'] ?? null, $load['pickup_timezone'] ?? null],
            [$load['delivery_date'] ?? null, $load['delivery_time'] ?? null, $load['delivery_timezone'] ?? null],
        ]);
    }

    /**
     * Whether the two share any moment. Back to back - one ending as the other
     * starts - does not overlap.
     */
    public function overlaps(self $other): bool
    {
        return $this->start->lt($other->end) && $other->start->lt($this->end);
    }

    /**
     * For the broker, each end in its own zone: "from Oct 14, 2026 9:00 AM CDT
     * to Oct 15, 2026 5:00 PM EDT", or "on Oct 14, 2026" for one day with no
     * times.
     */
    public function describe(): string
    {
        $from = self::format($this->start, $this->startHasTime);
        $to = self::format($this->end, $this->endHasTime);

        return $from === $to ? "on {$from}" : "from {$from} to {$to}";
    }

    /**
     * The IANA zone a timezone label names, or null when it names none.
     */
    public static function zoneFor(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }

        $label = trim((string) preg_replace('/^\(UTC[^)]*\)\s*/i', '', trim($label)));

        if ($label === '') {
            return null;
        }

        $key = strtolower($label);

        if (isset(self::ZONES[$key])) {
            return self::ZONES[$key];
        }

        self::$ianaIds ??= array_change_key_case(
            array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())
        );

        return self::$ianaIds[$key] ?? null;
    }

    /**
     * @param  array<int, array{0: mixed, 1: mixed, 2: mixed}>  $times  [date, time, timezone label]
     */
    private static function spanning(array $times): ?self
    {
        $fallbackZone = 'UTC';

        foreach ($times as [$date, , $label]) {
            if (self::day($date) !== null && ($zone = self::zoneFor($label)) !== null) {
                $fallbackZone = $zone;

                break;
            }
        }

        $start = $end = null;
        $startHasTime = $endHasTime = false;

        foreach ($times as [$date, $time, $label]) {
            $day = self::day($date);

            if ($day === null) {
                continue;
            }

            $zone = self::zoneFor($label) ?? $fallbackZone;
            $clock = self::clock($time);

            if ($clock !== null) {
                $earliest = $latest = CarbonImmutable::parse("{$day} {$clock}", $zone);
            } else {
                $earliest = CarbonImmutable::parse($day, $zone)->startOfDay();
                $latest = $earliest->endOfDay();
            }

            if ($start === null || $earliest->lt($start)) {
                $start = $earliest;
                $startHasTime = $clock !== null;
            }

            if ($end === null || $latest->gt($end)) {
                $end = $latest;
                $endHasTime = $clock !== null;
            }
        }

        return $start === null ? null : new self($start, $end, $startHasTime, $endHasTime);
    }

    /**
     * 'Y-m-d' from a date as posted ("2026-10-14") or as a DATE column comes
     * back (the string, or a Carbon from a `date` cast); null for anything else.
     */
    private static function day(mixed $date): ?string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        if (! is_string($date)
            || preg_match('/^\s*(\d{4})-(\d{2})-(\d{2})(?=$|[\sT])/', $date, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /**
     * 'H:i' from the time picker's "09:00" (or "9:00", "09:00:00", "9:00 AM");
     * null for no time, or one that can't be read - the date then holds the
     * whole day.
     */
    private static function clock(mixed $time): ?string
    {
        if (! is_string($time)
            || preg_match('/^\s*(\d{1,2}):(\d{2})(?::\d{2})?\s*([AaPp][Mm])?\s*$/', $time, $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if (($m[3] ?? '') !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }

            $hour = $hour % 12 + (strtolower($m[3]) === 'pm' ? 12 : 0);
        }

        return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    private static function format(CarbonImmutable $at, bool $hasTime): string
    {
        return $at->format($hasTime ? 'M j, Y g:i A T' : 'M j, Y');
    }
}
