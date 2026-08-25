<?php

namespace App\Services\Vin;

use App\Models\CarrierFleetStat;
use App\Models\VinPattern;
use App\Support\Vin;
use Illuminate\Support\Facades\DB;

/**
 * Average power-unit and trailer age for a carrier, computed from the decoded
 * VIN patterns and stored in carrier_fleet_stats.
 *
 * Two decisions worth knowing about:
 *
 * 1. Ages are averaged over *distinct VINs*, not over inspection rows. A truck
 *    stopped nine times would otherwise count nine times and drag the average
 *    toward whichever unit gets pulled over most.
 *
 * 2. Power unit vs. trailer comes from vPIC's own VehicleType/BodyClass, not
 *    from the FMCSA feed's unit_type_desc. The feed writes 'TRUCK TRACTOR',
 *    'STRAIGHT TRUCK', 'TRACTOR' and more for the same thing; the VIN says it
 *    unambiguously. Where a VIN never decoded, the feed's text is the fallback
 *    — but such a unit has no model year either, so it contributes to neither
 *    average.
 */
class FleetStatsService
{
    public function __construct(protected VinDecoderService $decoder) {}

    /**
     * Recompute and store one carrier's fleet age.
     */
    public function refresh(string $dot): CarrierFleetStat
    {
        $vins = $this->distinctVins($dot);
        $patterns = Vin::patterns($vins);

        $decoded = $patterns
            ? VinPattern::query()->decoded()->whereIn('pattern', $patterns)->get()->keyBy('pattern')
            : collect();

        $currentYear = (int) date('Y');
        $maxAge = (int) config('vin.max_plausible_age', 60);

        $powerAges = [];
        $trailerAges = [];
        $decodedVins = 0;

        foreach ($vins as $vin) {
            $pattern = Vin::pattern($vin);

            if (! $pattern || ! $hit = $decoded->get($pattern)) {
                continue;
            }

            $decodedVins++;

            if (! $hit->model_year) {
                continue;
            }

            $age = $currentYear - $hit->model_year;

            // A negative age is vPIC reporting next year's model; anything
            // past max_plausible_age is a bad decode rather than a real truck.
            if ($age < 0 || $age > $maxAge) {
                continue;
            }

            if ($hit->is_trailer) {
                $trailerAges[] = $age;
            } else {
                $powerAges[] = $age;
            }
        }

        $stat = CarrierFleetStat::query()->firstOrNew(['dot_number' => $dot]);

        $stat->fill([
            'avg_power_age' => $this->average($powerAges),
            'avg_trailer_age' => $this->average($trailerAges),
            'power_units' => count($powerAges),
            'trailers' => count($trailerAges),
            'vins_total' => count($vins),
            'vins_decoded' => $decodedVins,
            'computed_at' => now(),
        ])->save();

        // Anything this carrier runs that has never been decoded goes to the
        // queue, so the next refresh has more to work with.
        $this->decoder->queueUnknown($vins);

        return $stat;
    }

    /**
     * Every distinct VIN this carrier has ever been inspected with, from both
     * unit columns.
     *
     * idx_dot_date covers the dot_number lookup, and the row count per carrier
     * is bounded in a way the whole table is not.
     *
     * @return array<int, string>
     */
    protected function distinctVins(string $dot): array
    {
        $rows = DB::connection('external_db')->select("
            SELECT vin AS v FROM inspections
             WHERE dot_number = ? AND CHAR_LENGTH(vin) = 17
            UNION
            SELECT vin2 AS v FROM inspections
             WHERE dot_number = ? AND CHAR_LENGTH(vin2) = 17
        ", [$dot, $dot]);

        $vins = [];

        foreach ($rows as $row) {
            if (Vin::isValid($row->v)) {
                $vins[Vin::normalize($row->v)] = true;
            }
        }

        return array_keys($vins);
    }

    /**
     * Read the stored figures without recomputing. This is what the request
     * path calls — it never triggers a refresh.
     */
    public function forCarrier(string $dot): array
    {
        $stat = CarrierFleetStat::query()->find($dot);

        return $stat ? $stat->toPayload() : CarrierFleetStat::emptyPayload();
    }

    /**
     * @param  array<int, int>  $ages
     */
    protected function average(array $ages): ?float
    {
        if (! $ages) {
            return null;
        }

        return round(array_sum($ages) / count($ages), 1);
    }
}
