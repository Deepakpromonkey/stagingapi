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

    /*
    | BASIC percentiles, FMCSA's way: a carrier's measure is ranked only
    | against carriers with a similar number of safety events (its peer
    | group), and only among carriers with violations in that BASIC. The
    | group bounds and floors are methodology, so they live here and move
    | by versioned release - never as a tunable in config/dtscore.php.
    |
    | v2 in the key: the old flat {basic: {50, 75, 90}} shape must never be
    | read as the grouped one.
    */
    public const SMS_CACHE_KEY = 'carrier:sms_cuts:v2';

    /** count = relevant-event column; min = FMCSA data floor; bounds = group edges (<=). */
    public const SMS_GROUPS = [
        'unsafe_driv' => ['count' => 'unsafe_driv_insp_w_viol', 'min' => 3, 'bounds' => [8, 20, 100, 500]],
        'hos_driv' => ['count' => 'driver_insp_total', 'min' => 5, 'bounds' => [10, 20, 100, 500]],
        'driv_fit' => ['count' => 'driver_insp_total', 'min' => 5, 'bounds' => [10, 20, 100, 500]],
        'contr_subst' => ['count' => 'driver_insp_total', 'min' => 5, 'bounds' => [10, 20, 100, 500]],
        'veh_maint' => ['count' => 'vehicle_insp_total', 'min' => 5, 'bounds' => [10, 20, 100, 500]],
    ];

    /** What each BASIC's relevant-event count counts, for labels. */
    public const SMS_EVENT_LABELS = [
        'unsafe_driv' => 'inspections with an Unsafe Driving violation',
        'hos_driv' => 'driver inspections',
        'driv_fit' => 'driver inspections',
        'contr_subst' => 'driver inspections',
        'veh_maint' => 'vehicle inspections',
    ];

    /** The cut-points emitted per group. */
    public const SMS_PERCENTILES = [50, 65, 75, 80, 90];

    /** Date of the measured snapshot below, the vintage until a refresh runs. */
    public const SMS_DEFAULTS_VINTAGE = '2026-10-07';

    /** Measured 2026-10-07 over 729,900 SMS rows. [basic][group][percentile] => cut. */
    public const SMS_DEFAULTS = [
        'unsafe_driv' => [
            1 => [50 => 4.10, 65 => 6.54, 75 => 9.33, 80 => 11.46, 90 => 19.76],
            2 => [50 => 2.97, 65 => 4.25, 75 => 5.65, 80 => 6.77, 90 => 10.64],
            3 => [50 => 2.59, 65 => 3.54, 75 => 4.44, 80 => 5.12, 90 => 7.79],
            4 => [50 => 1.43, 65 => 2.11, 75 => 2.69, 80 => 2.95, 90 => 4.37],
            5 => [50 => 1.15, 65 => 1.36, 75 => 1.40, 80 => 1.45, 90 => 1.72],
        ],
        'hos_driv' => [
            1 => [50 => 1.31, 65 => 2.00, 75 => 2.63, 80 => 3.08, 90 => 4.53],
            2 => [50 => 0.87, 65 => 1.36, 75 => 1.87, 80 => 2.23, 90 => 3.36],
            3 => [50 => 0.50, 65 => 0.80, 75 => 1.12, 80 => 1.35, 90 => 2.13],
            4 => [50 => 0.27, 65 => 0.40, 75 => 0.54, 80 => 0.64, 90 => 0.98],
            5 => [50 => 0.12, 65 => 0.19, 75 => 0.27, 80 => 0.31, 90 => 0.49],
        ],
        'driv_fit' => [
            1 => [50 => 1.17, 65 => 1.66, 75 => 2.20, 80 => 2.50, 90 => 3.57],
            2 => [50 => 0.68, 65 => 1.00, 75 => 1.30, 80 => 1.50, 90 => 2.24],
            3 => [50 => 0.34, 65 => 0.52, 75 => 0.71, 80 => 0.86, 90 => 1.38],
            4 => [50 => 0.14, 65 => 0.21, 75 => 0.30, 80 => 0.38, 90 => 0.83],
            5 => [50 => 0.07, 65 => 0.11, 75 => 0.17, 80 => 0.22, 90 => 0.66],
        ],
        'contr_subst' => [
            1 => [50 => 1.42, 65 => 1.81, 75 => 2.14, 80 => 2.35, 90 => 3.33],
            2 => [50 => 0.66, 65 => 0.85, 75 => 1.03, 80 => 1.15, 90 => 1.60],
            3 => [50 => 0.22, 65 => 0.30, 75 => 0.40, 80 => 0.47, 90 => 0.68],
            4 => [50 => 0.05, 65 => 0.07, 75 => 0.09, 80 => 0.10, 90 => 0.15],
            5 => [50 => 0.02, 65 => 0.03, 75 => 0.03, 80 => 0.04, 90 => 0.05],
        ],
        'veh_maint' => [
            1 => [50 => 5.55, 65 => 7.62, 75 => 9.42, 80 => 10.58, 90 => 13.93],
            2 => [50 => 5.09, 65 => 6.82, 75 => 8.24, 80 => 9.15, 90 => 11.94],
            3 => [50 => 4.44, 65 => 5.85, 75 => 7.15, 80 => 7.96, 90 => 10.58],
            4 => [50 => 3.65, 65 => 4.75, 75 => 5.88, 80 => 6.61, 90 => 9.27],
            5 => [50 => 2.72, 65 => 3.53, 75 => 4.58, 80 => 5.09, 90 => 7.60],
        ],
    ];

    /** Carriers ranked per group in the 2026-10-07 snapshot. [basic][group] => n. */
    public const SMS_DEFAULTS_N = [
        'unsafe_driv' => [1 => 34300, 2 => 6505, 3 => 2671, 4 => 241, 5 => 18],
        'hos_driv' => [1 => 36610, 2 => 20478, 3 => 21304, 4 => 4819, 5 => 693],
        'driv_fit' => [1 => 23966, 2 => 15031, 3 => 18293, 4 => 4997, 5 => 791],
        'contr_subst' => [1 => 1912, 2 => 1442, 3 => 2094, 4 => 920, 5 => 161],
        'veh_maint' => [1 => 52818, 2 => 22054, 3 => 19183, 4 => 3496, 5 => 493],
    ];

    /** @return array<string, array<int, array<int, float>>> [basic][group][percentile] => cut */
    public static function smsCuts(): array
    {
        $cached = self::smsCached();

        return is_array($cached['cuts'] ?? null) && $cached['cuts'] !== [] ? $cached['cuts'] : self::SMS_DEFAULTS;
    }

    /** generated_at and groups_n of the last refresh, or null before the first one. */
    public static function smsCutsMeta(): ?array
    {
        $cached = self::smsCached();

        return is_array($cached['meta'] ?? null) ? $cached['meta'] : null;
    }

    /** The benchmark edition judging a score: the refresh date, or the shipped snapshot's. */
    public static function smsVintage(): string
    {
        $at = self::smsCutsMeta()['generated_at'] ?? null;

        return $at ? substr((string) $at, 0, 10) : self::SMS_DEFAULTS_VINTAGE;
    }

    /** The carrier's relevant-event count for a BASIC. */
    public static function smsEvents(string $basic, $sms): int
    {
        return (int) ($sms?->{self::SMS_GROUPS[$basic]['count']} ?? 0);
    }

    /** Peer group 1-5 for an event count, or null below FMCSA's data floor. */
    public static function smsGroup(string $basic, int $events): ?int
    {
        $spec = self::SMS_GROUPS[$basic];

        if ($events < $spec['min']) {
            return null;
        }

        return 1 + count(array_filter($spec['bounds'], fn ($b) => $events > $b));
    }

    /** e.g. "5–10 vehicle inspections", "501+ driver inspections". */
    public static function smsGroupLabel(string $basic, int $group): string
    {
        $spec = self::SMS_GROUPS[$basic];
        $edges = array_merge([$spec['min'] - 1], $spec['bounds']);

        $low = $edges[$group - 1] + 1;
        $high = $spec['bounds'][$group - 1] ?? null;

        return ($high === null ? "{$low}+" : "{$low}–{$high}").' '.self::SMS_EVENT_LABELS[$basic];
    }

    /** Carriers ranked in a group, from the last refresh or the snapshot. */
    public static function smsGroupN(string $basic, int $group): ?int
    {
        $n = self::smsCutsMeta()['groups_n'][$basic][$group] ?? self::SMS_DEFAULTS_N[$basic][$group] ?? null;

        return $n === null ? null : (int) $n;
    }

    /**
     * Estimated percentile of a measure inside its group: piecewise-linear
     * over (0,0), (p50,50) ... (p90,90), extrapolated past p90 on the last
     * segment and clamped at 99. Always shown with "≈": the bands are the
     * truth, the estimate is an aid.
     *
     * @param  array<int, float>  $cuts
     */
    public static function percentileEstimate(float $measure, array $cuts): ?int
    {
        $anchors = [[0.0, 0]];

        foreach (self::SMS_PERCENTILES as $p) {
            if (isset($cuts[$p])) {
                $anchors[] = [(float) $cuts[$p], $p];
            }
        }

        if (count($anchors) < 2 || $measure <= 0) {
            return $measure <= 0 ? 0 : null;
        }

        for ($i = 1; $i < count($anchors); $i++) {
            [$x0, $y0] = $anchors[$i - 1];
            [$x1, $y1] = $anchors[$i];

            $last = $i === count($anchors) - 1;

            if ($measure <= $x1 || $last) {
                if ($x1 <= $x0) {
                    $pct = $measure >= $x1 ? $y1 : $y0;
                } else {
                    $pct = $y0 + ($measure - $x0) * ($y1 - $y0) / ($x1 - $x0);
                }

                return (int) max(0, min(99, round($pct)));
            }
        }

        return null;
    }

    /**
     * over / elevated / clear / not_ranked for one BASIC.
     *
     * @param  array<int, float>|null  $cuts  the carrier's group cuts; null when not ranked
     */
    public static function smsVerdict(?float $measure, ?array $cuts, int $threshold): string
    {
        if ($cuts === null) {
            return 'not_ranked';
        }

        $measure = (float) ($measure ?? 0);
        $cut = (float) ($cuts[$threshold] ?? 0);

        if ($measure > 0 && $cut > 0 && $measure >= $cut) {
            return 'over';
        }

        if ($measure > 0 && $measure >= (float) ($cuts[50] ?? INF)) {
            return 'elevated';
        }

        return 'clear';
    }

    /**
     * Everything a client needs to show one BASIC: the measure, its peer
     * group and that group's cuts, the threshold, the estimated percentile
     * and the verdict.
     */
    public static function smsBasicView(string $basic, $sms, int $threshold, ?array $allCuts = null): array
    {
        $allCuts ??= self::smsCuts();

        $events = self::smsEvents($basic, $sms);
        $group = self::smsGroup($basic, $events);
        $measure = $sms?->{"{$basic}_measure"};
        $measure = $measure === null ? null : (float) $measure;

        $cuts = $group === null ? null : ($allCuts[$basic][$group] ?? null);

        return [
            'measure' => $measure,
            'events' => $events,
            'events_label' => self::SMS_EVENT_LABELS[$basic],
            'floor' => self::SMS_GROUPS[$basic]['min'],
            'peer_group' => $group,
            'peer_group_label' => $group === null ? null : self::smsGroupLabel($basic, $group),
            'group_n' => $group === null ? null : self::smsGroupN($basic, $group),
            'cuts' => $cuts === null ? null : array_map('floatval', $cuts),
            'threshold_pct' => $threshold,
            'percentile_est' => $cuts === null || $measure === null ? null : self::percentileEstimate($measure, $cuts),
            'verdict' => self::smsVerdict($measure, $cuts, $threshold),
        ];
    }

    private static function smsCached(): ?array
    {
        $cached = Cache::store(config('cache.durable_store'))->get(self::SMS_CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    public static function all(): array
    {
        return Cache::store(config('cache.durable_store'))->get(self::CACHE_KEY, []) + self::DEFAULTS;
    }
}
