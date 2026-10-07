<?php

namespace App\Services\Eld;

use App\Models\Eld\EldConnection;
use App\Models\Eld\EldLocation;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Live positions for loads in flight.
 *
 * One call per connection, not per load and not per truck. /vehicles/locations
 * takes up to fifty vehicle ids and answers with the latest ping for each, so a
 * carrier hauling six of our loads costs one provider call a cycle rather than
 * six. That is the whole reason this does not reuse EldSyncService, which walks
 * position history a vehicle at a time and is billed accordingly.
 *
 * Positions land in eld_locations like any other, so the unique key on
 * (connection, vehicle, located_at) collapses a repeat read of a truck that has
 * not moved, and nothing here has to remember what it already saw.
 */
class EldTrackingService
{
    /**
     * How close counts as "arrived" at origin or destination. 1 mile —
     * tight enough to be meaningful, loose enough to absorb GPS drift and a
     * large facility yard without missing the crossing.
     */
    private const GEOFENCE_RADIUS_MILES = 1.0;

    public function __construct(private TerminalClient $terminal) {}

    /**
     * Every carrier with at least one load due a position check.
     */
    public function pollAll(): void
    {
        if (! $this->terminal->isConfigured()) {
            return;
        }

        Shipment::eldTracking()
            ->select('eld_connection_id')
            ->distinct()
            ->pluck('eld_connection_id')
            ->each(function ($connectionId) {
                $connection = EldConnection::find($connectionId);

                if ($connection?->isSyncable()) {
                    $this->pollConnection($connection);
                }
            });
    }

    /**
     * @return int the number of positions written
     */
    public function pollConnection(EldConnection $connection): int
    {
        $due = $this->dueShipments($connection);

        if ($due->isEmpty()) {
            return 0;
        }

        $byVehicle = $due->groupBy('eld_vehicle_terminal_id');

        try {
            $rows = $this->terminal->latestVehicleLocations(
                $connection->connection_token,
                $byVehicle->keys()->all()
            );
        } catch (TerminalPermissionException $e) {
            /*
            | An entitlement, not a fault, and it will answer the same way every
            | cycle — so it is written onto the connection where a broker can
            | see it rather than logged into a file nobody reads.
            */
            $connection->forceFill([
                'last_sync_error' => 'Live location is not available to this Terminal account: '.$e->getMessage(),
            ])->save();

            return 0;
        } catch (TerminalRequestException $e) {
            // A provider having a bad minute. Left for the next cycle; nothing
            // is checkpointed here, so there is no window to lose.
            Log::warning('ELD live location poll failed', [
                'connection' => $connection->uuid,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $written = 0;

        foreach ($rows as $row) {
            $vehicleId = (string) data_get($row, 'vehicle');
            $locatedAt = $this->timestamp(data_get($row, 'locatedAt'));

            if ($vehicleId === '' || $locatedAt === null) {
                continue;
            }

            EldLocation::updateOrCreate(
                [
                    'eld_connection_id' => $connection->id,
                    'vehicle_terminal_id' => $vehicleId,
                    'located_at' => $locatedAt,
                ],
                [
                    'latitude' => data_get($row, 'location.latitude'),
                    'longitude' => data_get($row, 'location.longitude'),
                    'speed_mph' => data_get($row, 'speed'),
                    'heading_degrees' => data_get($row, 'heading'),
                    'odometer_miles' => data_get($row, 'odometer'),
                    'description' => data_get($row, 'address.formatted'),
                    'payload' => $row,
                ]
            );

            $written++;

            $lat = data_get($row, 'location.latitude');
            $lng = data_get($row, 'location.longitude');

            foreach ($byVehicle->get($vehicleId, collect()) as $shipment) {
                $updates = [];

                /*
                | last_ping_at is the column the control tower and the
                | stale-load alerting already watch, filled by the driver app
                | on a phone-tracked load. Writing it here is what makes an
                | ELD load visible to both without either of them learning
                | what an ELD is.
                |
                | The provider's timestamp, not ours: a truck parked in a yard
                | with a two hour old position has not pinged, and stamping
                | now() would tell the alerting otherwise.
                */
                if (! $shipment->last_ping_at || $locatedAt->greaterThan($shipment->last_ping_at)) {
                    $updates['last_ping_at'] = $locatedAt;
                }

                if ($lat !== null && $lng !== null) {
                    $updates += $this->arrivalUpdates($shipment, (float) $lat, (float) $lng, $locatedAt);
                }

                if ($updates !== []) {
                    $shipment->forceFill($updates)->save();
                }
            }
        }

        return $written;
    }

    /**
     * Which of the two milestone columns this ping crosses, if either —
     * empty if neither, so the caller can merge this straight into whatever
     * else is being written for the shipment this cycle without an extra
     * round trip.
     *
     * Written once and never cleared: a truck that arrives, repositions
     * within the yard and drifts back out past the radius has still arrived
     * — the first crossing is the event, not a live in/out flag.
     *
     * Silently does nothing for a shipment with no origin/destination
     * coordinates — a broker who typed an address by hand instead of picking
     * it from the map autocomplete has nothing here to geofence against.
     */
    private function arrivalUpdates(Shipment $shipment, float $lat, float $lng, Carbon $locatedAt): array
    {
        $updates = [];

        if (! $shipment->arrived_at_origin_at
            && $shipment->origin_lat !== null
            && $shipment->origin_lng !== null
            && $this->milesBetween($lat, $lng, $shipment->origin_lat, $shipment->origin_lng) <= self::GEOFENCE_RADIUS_MILES) {
            $updates['arrived_at_origin_at'] = $locatedAt;
        }

        if (! $shipment->arrived_at_destination_at
            && $shipment->destination_lat !== null
            && $shipment->destination_lng !== null
            && $this->milesBetween($lat, $lng, $shipment->destination_lat, $shipment->destination_lng) <= self::GEOFENCE_RADIUS_MILES) {
            $updates['arrived_at_destination_at'] = $locatedAt;
        }

        return $updates;
    }

    /**
     * Great-circle distance in miles — the Haversine formula, accurate
     * enough for a "did the truck reach the yard" check without needing a
     * geo library for one call site.
     */
    private function milesBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMiles = 3958.8;

        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;

        return $earthRadiusMiles * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Loads on this connection whose interval has elapsed.
     *
     * tracking_interval_seconds is the broker's choice per load, so two loads
     * on the same truck at different intervals both get what they asked for —
     * and because the call is batched by connection, the tighter of the two
     * costs nothing extra for the other.
     */
    private function dueShipments(EldConnection $connection)
    {
        return Shipment::eldTracking()
            ->where('eld_connection_id', $connection->id)
            ->get()
            ->filter(function (Shipment $shipment) {
                if (! $shipment->last_ping_at) {
                    return true;
                }

                $interval = (int) ($shipment->tracking_interval_seconds ?: 300);

                // A shade under the interval, because the scheduler fires on a
                // whole minute and an exact comparison makes every 300 second
                // load poll every 360.
                return $shipment->last_ping_at->addSeconds($interval - 30)->isPast();
            });
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}