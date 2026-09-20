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

            /*
            | last_ping_at is the column the control tower and the stale-load
            | alerting already watch, filled by the driver app on a phone-
            | tracked load. Writing it here is what makes an ELD load visible to
            | both without either of them learning what an ELD is.
            |
            | The provider's timestamp, not ours: a truck parked in a yard with
            | a two hour old position has not pinged, and stamping now() would
            | tell the alerting otherwise.
            */
            foreach ($byVehicle->get($vehicleId, collect()) as $shipment) {
                if (! $shipment->last_ping_at || $locatedAt->greaterThan($shipment->last_ping_at)) {
                    $shipment->forceFill(['last_ping_at' => $locatedAt])->save();
                }
            }
        }

        return $written;
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