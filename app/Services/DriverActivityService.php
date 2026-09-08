<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything the driver app recorded against a shipment.
 *
 * The driver app is a separate Laravel application that keeps its own tables in
 * this same database, so this reads those tables directly rather than through
 * relations on the broker's models. One query per table for the whole load —
 * never per stop — and only the columns the control tower is allowed to show
 * (no password hashes, no OTP hashes).
 */
class DriverActivityService
{
    /**
     * Hard ceiling on GPS pings returned in one response.
     *
     * A multi-day haul pinging every few minutes runs to thousands of rows, and
     * the control tower only needs a drawable trail. When the cap bites, the
     * trail is thinned evenly across the whole trip rather than truncated, so
     * the shape of the route survives and the newest position is always kept.
     */
    public const MAX_PINGS = 1500;

    /**
     * Columns of the driver's own profile that a broker may see.
     */
    private const DRIVER_COLUMNS = [
        'id', 'uuid', 'first_name', 'last_name', 'profile_picture', 'email', 'phone',
        'carrier_name', 'status', 'tracking_interval_seconds',
        'cdl_number', 'cdl_state', 'cdl_expiration',
        'liveness_verified', 'liveness_status',
        'dob', 'address', 'city', 'state', 'zip',
        'created_at',
    ];

    /**
     * Attach the driver's activity onto a shipment row, in place.
     *
     * @param  object  $shipment  a shipments row that already carries ->stops
     */
    public function attachTo(object $shipment): object
    {
        $stops = collect($shipment->stops ?? []);

        $progress = $this->stopProgress($shipment->id);
        $otps = $this->stopVerifications($shipment->id);

        $shipment->stops = $stops->map(function ($stop) use ($progress, $otps) {
            $stop->progress = $progress->get($stop->id);
            $stop->verifications = $otps->get($stop->id, collect())->values();

            return $stop;
        })->values();

        $shipment->journey = $this->journey($shipment->id);
        $shipment->equipment_verification = $this->equipmentVerification($shipment->id);

        $pings = $this->locationPings($shipment->uuid);
        $shipment->location_pings = $pings['pings'];
        $shipment->location_ping_count = $pings['total'];
        $shipment->location_pings_thinned = $pings['thinned'];

        $shipment->driver = $this->driver($shipment);

        return $shipment;
    }

    /**
     * Per-stop arrival, OTP, seal, condition and POD, keyed by stop id.
     */
    private function stopProgress(int $shipmentId)
    {
        return DB::table('shipment_stop_progress')
            ->where('shipment_id', $shipmentId)
            ->orderBy('stop_number')
            ->get()
            ->keyBy('shipment_stop_id');
    }

    /**
     * The OTP trail for each stop — proof a code was sent and consumed.
     *
     * `code_hash` is deliberately never selected.
     */
    private function stopVerifications(int $shipmentId)
    {
        return DB::table('stop_verification_otps')
            ->where('shipment_id', $shipmentId)
            ->orderBy('id')
            ->get(['id', 'shipment_stop_id', 'purpose', 'sent_to', 'attempts', 'expires_at', 'consumed_at', 'created_at'])
            ->groupBy('shipment_stop_id');
    }

    /**
     * The single-journey summary the driver app maintains alongside per-stop
     * progress: which step it is on, and the shipper/receiver milestones.
     */
    private function journey(int $shipmentId)
    {
        return DB::table('shipment_journeys')
            ->where('shipment_id', $shipmentId)
            ->latest('id')
            ->first();
    }

    /**
     * VIN / tractor / trailer photos and the OCR text read off them.
     */
    private function equipmentVerification(int $shipmentId)
    {
        return DB::table('equipment_verifications')
            ->where('shipment_id', $shipmentId)
            ->latest('id')
            ->first();
    }

    /**
     * The GPS trail, oldest first, with the device clock resolved.
     *
     * `device_timestamp` is epoch milliseconds straight off the phone. It is
     * exposed as an ISO string so the UI does not have to know that, while
     * `created_at` (server receipt) is kept for comparison — a large gap
     * between them means the phone was offline and batched its pings.
     *
     * @return array{pings: \Illuminate\Support\Collection, total: int, thinned: bool}
     */
    private function locationPings(?string $shipmentUuid): array
    {
        if (! $shipmentUuid) {
            return ['pings' => collect(), 'total' => 0, 'thinned' => false];
        }

        $query = DB::table('driver_locations')->where('shipment_uuid', $shipmentUuid);

        $total = (clone $query)->count();

        $rows = $query->orderBy('id')
            ->get(['id', 'driver_id', 'lat', 'lng', 'accuracy', 'device_timestamp', 'created_at']);

        $thinned = $total > self::MAX_PINGS;

        if ($thinned) {
            // Keep every Nth ping plus the final one, so the route still reads
            // correctly and the marker sits on the driver's latest position.
            $step = (int) ceil($total / self::MAX_PINGS);
            $last = $rows->last();

            $rows = $rows->values()->filter(fn ($row, $index) => $index % $step === 0)->values();

            if ($last && $rows->last()?->id !== $last->id) {
                $rows->push($last);
            }
        }

        $pings = $rows->map(function ($row) {
            $row->lat = (float) $row->lat;
            $row->lng = (float) $row->lng;
            $row->accuracy = $row->accuracy === null ? null : (float) $row->accuracy;
            $row->recorded_at = $this->fromDeviceTimestamp($row->device_timestamp) ?? $row->created_at;

            return $row;
        });

        return ['pings' => $pings->values(), 'total' => $total, 'thinned' => $thinned];
    }

    /**
     * Epoch milliseconds from the phone -> a datetime string.
     *
     * Seconds are accepted too: a 10-digit value is far too small to be
     * milliseconds and would otherwise land in 1970.
     */
    private function fromDeviceTimestamp($value): ?string
    {
        if (blank($value) || ! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        try {
            return ($value > 99999999999
                ? Carbon::createFromTimestampMs($value)
                : Carbon::createFromTimestamp($value)
            )->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Who was driving.
     *
     * The shipment itself only holds the phone numbers the broker typed in, so
     * the driver is identified from the activity actually recorded against this
     * load — stop progress first, then the journey, then the GPS trail. Falling
     * back to a phone-number match would risk naming a driver who never ran it.
     */
    private function driver(object $shipment)
    {
        $driverId = collect($shipment->stops ?? [])
            ->pluck('progress.driver_id')
            ->push($shipment->journey->driver_id ?? null)
            ->push(collect($shipment->location_pings ?? [])->last()->driver_id ?? null)
            ->push($shipment->equipment_verification->driver_id ?? null)
            ->filter()
            ->first();

        if (! $driverId) {
            return null;
        }

        $driver = DB::table('app_drivers')->where('id', $driverId)->first(self::DRIVER_COLUMNS);

        if ($driver) {
            $driver->name = trim(($driver->first_name ?? '').' '.($driver->last_name ?? '')) ?: null;
        }

        return $driver;
    }
}
