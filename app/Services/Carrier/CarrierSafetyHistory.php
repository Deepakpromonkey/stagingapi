<?php

namespace App\Services\Carrier;

use App\Services\Vin\VinDecoderService;
use App\Support\Vin;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A carrier's inspection, violation and crash-detail history for the profile.
 *
 * These lists used to ride along in CarrierController::detail(). On a large
 * fleet they are tens of thousands of rows — 45-67 MB of JSON and several
 * hundred MB of PHP memory as Eloquent models — which no request was allowed
 * to finish, so the carriers brokers look at most were the ones whose profile
 * would not open.
 *
 * Here they are read as plain rows, only the columns the profile's components
 * use, written into a gzip stream one row at a time, and cached compressed.
 * Each value is shaped exactly as the models serialised it, so the components
 * compute the same figures from it.
 */
class CarrierSafetyHistory
{
    /** Read by the profile components (Fleet, Inspections, Basics, Observations, Benchmarks). */
    public const INSPECTION_COLUMNS = [
        'id', 'row_id', 'unique_id', 'report_number', 'report_state', 'dot_number',
        'insp_date', 'insp_level_id', 'county_code_state', 'oos_total',
        'unit_type_desc', 'unit_make', 'unit_license', 'unit_license_state', 'vin',
        'unit_type_desc2', 'unit_make2', 'unit_license2', 'vin2',
        'basic_viol', 'subt_alcohol_viol', 'hm_viol',
    ];

    public const VIOLATION_COLUMNS = [
        'id', 'row_id', 'unique_id', 'insp_date', 'dot_number', 'viol_code',
        'basic_desc', 'severity_weight', 'section_desc', 'group_desc',
    ];

    public const CRASH_DETAIL_COLUMNS = [
        'id', 'row_id', 'crash_id', 'report_state', 'report_number', 'report_date',
        'dot_number', 'location', 'city', 'state', 'agency', 'fatalities', 'injuries', 'tow_away',
    ];

    private const CACHE_VERSION = 'v1';

    public function __construct(private VinDecoderService $vinDecoder) {}

    /** The response body, gzip-compressed: {"success":true,"data":{...}}. */
    public function gzippedJson(string $dot): string
    {
        $encoded = Cache::remember(
            'carrier:safety-history:'.self::CACHE_VERSION.':'.$dot,
            (int) config('carriers.profile_cache_ttl', 900),
            // Base64 so the value survives any cache store, the database
            // fallback included.
            fn () => base64_encode($this->build($dot)),
        );

        return base64_decode($encoded);
    }

    public function forget(string $dot): void
    {
        Cache::forget('carrier:safety-history:'.self::CACHE_VERSION.':'.$dot);
    }

    private function build(string $dot): string
    {
        $db = DB::connection('external_db');

        $gzip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        $out = '';
        $write = function (string $json) use ($gzip, &$out) {
            $out .= deflate_add($gzip, $json, ZLIB_NO_FLUSH);
        };

        // Violations first: the inspection rows carry their own as well.
        $violations = $db->table('violation_details')
            ->select(self::VIOLATION_COLUMNS)
            ->whereIn('dot_number', [$dot])
            ->get()
            ->map(fn ($row) => $this->violation($row));

        $byInspection = $violations->groupBy('unique_id');

        $vins = [];
        foreach ($db->table('inspections')->select(['vin', 'vin2'])->whereIn('dot_number', [$dot])->cursor() as $row) {
            $vins[] = $row->vin;
            $vins[] = $row->vin2;
        }
        $decoded = $this->vinDecoder->lookup($vins);
        unset($vins);

        $write('{"success":true,"data":{"inspections":[');

        $first = true;
        foreach ($db->table('inspections')->select(self::INSPECTION_COLUMNS)->whereIn('dot_number', [$dot])->cursor() as $row) {
            $item = (array) $row;
            $item['insp_date'] = $row->insp_date ? Carbon::parse($row->insp_date)->format('Y-m-d') : null;
            $item['violation_details'] = $row->unique_id !== null
                ? ($byInspection->get($row->unique_id) ?? collect())->values()->all()
                : [];
            $item['vin_decoded'] = self::decodedVinFields($decoded, $row->vin);
            $item['vin2_decoded'] = self::decodedVinFields($decoded, $row->vin2);

            $write(($first ? '' : ',').json_encode($item));
            $first = false;
        }

        $write('],"violation_details":'.json_encode($violations->values()->all()));
        unset($violations, $byInspection);

        $write(',"crash_details":[');

        $first = true;
        foreach ($db->table('crash_details')->select(self::CRASH_DETAIL_COLUMNS)->whereIn('dot_number', [$dot])->cursor() as $row) {
            $item = (array) $row;
            // As CrashDetail casts them: date and boolean.
            $item['report_date'] = $this->isoDate($row->report_date);
            $item['tow_away'] = $row->tow_away === null ? null : (bool) $row->tow_away;

            $write(($first ? '' : ',').json_encode($item));
            $first = false;
        }

        $out .= deflate_add($gzip, ']}}', ZLIB_FINISH);

        return $out;
    }

    /** A violation row as ViolationDetail serialised it. */
    private function violation(object $row): array
    {
        $item = (array) $row;
        $item['insp_date'] = $this->isoDate($row->insp_date);
        $item['severity_weight'] = $row->severity_weight === null
            ? null
            : number_format((float) $row->severity_weight, 4, '.', '');

        return $item;
    }

    /** A `date` cast, serialised: midnight UTC in ISO 8601. */
    private function isoDate(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->startOfDay()->toJSON() : null;
    }

    /** Year / make / model decoded from a VIN, or nulls. */
    public static function decodedVinFields(Collection $decoded, ?string $vin): array
    {
        $hit = $decoded->get(Vin::normalize($vin));

        return [
            'model_year' => $hit?->model_year,
            'make' => $hit?->make,
            'model' => $hit?->model,
            'vehicle_type' => $hit?->vehicle_type,
            'body_class' => $hit?->body_class,
            'is_trailer' => $hit?->is_trailer,
        ];
    }
}
