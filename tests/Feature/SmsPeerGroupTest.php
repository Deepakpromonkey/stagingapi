<?php

namespace Tests\Feature;

use App\Support\CarrierBenchmarks as B;
use Tests\TestCase;

/**
 * FMCSA-style BASIC percentiles: peer group by relevant-event count, the
 * group's cut-points, the ≈percentile and the verdict.
 */
class SmsPeerGroupTest extends TestCase
{
    public function test_group_edges_follow_the_fmcsa_bounds(): void
    {
        $this->assertNull(B::smsGroup('hos_driv', 4), 'under the floor of 5: not ranked');
        $this->assertSame(1, B::smsGroup('hos_driv', 5));
        $this->assertSame(1, B::smsGroup('hos_driv', 10));
        $this->assertSame(2, B::smsGroup('hos_driv', 11));
        $this->assertSame(4, B::smsGroup('hos_driv', 500));
        $this->assertSame(5, B::smsGroup('hos_driv', 501));

        $this->assertNull(B::smsGroup('unsafe_driv', 2));
        $this->assertSame(1, B::smsGroup('unsafe_driv', 3));
        $this->assertSame(2, B::smsGroup('unsafe_driv', 9));
    }

    public function test_group_labels(): void
    {
        $this->assertSame('5–10 vehicle inspections', B::smsGroupLabel('veh_maint', 1));
        $this->assertSame('501+ driver inspections', B::smsGroupLabel('hos_driv', 5));
        $this->assertSame('3–8 inspections with an Unsafe Driving violation', B::smsGroupLabel('unsafe_driv', 1));
    }

    public function test_percentile_estimate_interpolates_and_clamps(): void
    {
        $cuts = B::SMS_DEFAULTS['veh_maint'][1]; // 5.55 / 7.62 / 9.42 / 10.58 / 13.93

        $this->assertSame(73, B::percentileEstimate(9.00, $cuts));
        $this->assertSame(50, B::percentileEstimate(5.55, $cuts));
        $this->assertSame(80, B::percentileEstimate(10.58, $cuts));
        $this->assertSame(99, B::percentileEstimate(500, $cuts));
        $this->assertSame(0, B::percentileEstimate(0, $cuts));
    }

    public function test_the_four_verdicts(): void
    {
        $cuts = B::SMS_DEFAULTS['veh_maint'][1];

        $this->assertSame('over', B::smsVerdict(10.58, $cuts, 80));
        $this->assertSame('elevated', B::smsVerdict(9.00, $cuts, 80));
        $this->assertSame('clear', B::smsVerdict(3.00, $cuts, 80));
        $this->assertSame('clear', B::smsVerdict(0.0, $cuts, 80), 'zero measure: no violations');
        $this->assertSame('not_ranked', B::smsVerdict(9.00, null, 80));
    }

    public function test_a_below_floor_carrier_is_not_ranked_with_its_measure_kept(): void
    {
        // DOT 2504132's case: a measure of 9 with one vehicle inspection.
        $sms = (object) ['veh_maint_measure' => 9.0, 'vehicle_insp_total' => 1];

        $view = B::smsBasicView('veh_maint', $sms, 80);

        $this->assertSame('not_ranked', $view['verdict']);
        $this->assertNull($view['peer_group']);
        $this->assertNull($view['percentile_est']);
        $this->assertSame(9.0, $view['measure']);
        $this->assertSame(5, $view['floor']);
        $this->assertSame(1, $view['events']);
    }

    public function test_drug_and_alcohol_cuts_are_alive_and_monotonic(): void
    {
        foreach (B::SMS_DEFAULTS as $basic => $groups) {
            $previous = INF;

            foreach ($groups as $group => $cuts) {
                $this->assertGreaterThan(0, $cuts[65], "{$basic} g{$group} 65th must not be zero");
                $this->assertGreaterThan(0, $cuts[80], "{$basic} g{$group} 80th must not be zero");
                $this->assertLessThanOrEqual($previous, $cuts[65], "{$basic}: p65 must fall as the group grows");
                $previous = $cuts[65];
            }
        }
    }

    public function test_vintage_falls_back_to_the_snapshot_date(): void
    {
        $this->assertSame('2026-10-07', B::smsVintage());
    }
}
