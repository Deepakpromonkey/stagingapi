<?php

namespace App\Http\Controllers\Api\V1\Eld;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Eld\EldLocation;
use App\Models\Shipment;
use App\Services\Eld\EldTrackingService;
use Illuminate\Http\Request;

class EldShipmentTrackingController extends BaseController
{
    public function __construct(private EldTrackingService $tracking) {}

    /**
     * Start tracking. This is the modal's Start button.
     *
     * Separate from creation on purpose: a broker books the load when the rate
     * is agreed and starts tracking when the truck is dispatched, which can be
     * a day apart, and polling a truck that is still on somebody else's load
     * costs money and tells nobody anything.
     *
     * The first poll runs inline so the map has a pin on it before the screen
     * finishes loading, rather than making the broker wait on the scheduler.
     */
    public function start(string $uuid)
    {
        $shipment = $this->shipment($uuid);

        if ($shipment->tracking_method !== 'eld' || ! $shipment->eld_connection_id) {
            return $this->error('This shipment is not set up for ELD tracking.', null, 422);
        }

        if ($shipment->eld_tracking_started_at && ! $shipment->eld_tracking_stopped_at) {
            return $this->error('Tracking is already running for this shipment.', null, 409);
        }

        $connection = $shipment->eldConnection;

        if (! $connection?->isSyncable()) {
            return $this->error(
                'This carrier\'s ELD connection is no longer live. Ask them to reconnect before starting.',
                null,
                422
            );
        }

        $shipment->forceFill([
            'eld_tracking_started_at' => now(),
            'eld_tracking_stopped_at' => null,

            // shipments.status is a fixed enum (draft/active/completed/
            // cancelled) — 'active' is also what marks a load as under way
            // everywhere else (the carrier portal, SyncActiveEldConnections'
            // "carriers with live loads" scope), so this is what makes an ELD
            // load count as active for those too, not a status of its own.
            'status' => 'active',
        ])->save();

        $this->tracking->pollConnection($connection);

        return $this->success($this->trackingPayload($shipment->fresh()), 'ELD tracking started.');
    }

    public function stop(string $uuid)
    {
        $shipment = $this->shipment($uuid);

        if ($shipment->tracking_method !== 'eld' || ! $shipment->eld_connection_id) {
            return $this->error('This shipment is not set up for ELD tracking.', null, 422);
        }

        if (! $shipment->eld_tracking_started_at || $shipment->eld_tracking_stopped_at) {
            return $this->error('Tracking is not currently running for this shipment.', null, 409);
        }

        $shipment->forceFill([
            'eld_tracking_stopped_at' => now(),
            'status' => 'completed',
        ])->save();

        return $this->success(null, 'ELD tracking stopped.');
    }

    /**
     * The map: where the truck is, and where it has been since Start.
     */
    public function track(Request $request, string $uuid)
    {
        $shipment = $this->shipment($uuid);

        if ($shipment->tracking_method !== 'eld') {
            return $this->error('This shipment is not ELD tracked.', null, 422);
        }

        return $this->success(
            $this->trackingPayload($shipment, $request->integer('trail', 200)),
            'ELD tracking retrieved successfully.'
        );
    }

    private function trackingPayload(Shipment $shipment, int $trailLimit = 200): array
    {
        /*
        | The trail is bounded at Start rather than pulled whole. eld_locations
        | holds everything the connection ever reported for that truck, across
        | every broker and every load it has run — this load's trail is the slice
        | since tracking began on it.
        */
        $trail = EldLocation::query()
            ->where('eld_connection_id', $shipment->eld_connection_id)
            ->where('vehicle_terminal_id', $shipment->eld_vehicle_terminal_id)
            ->when(
                $shipment->eld_tracking_started_at,
                fn ($q) => $q->where('located_at', '>=', $shipment->eld_tracking_started_at)
            )
            ->when(
                $shipment->eld_tracking_stopped_at,
                fn ($q) => $q->where('located_at', '<=', $shipment->eld_tracking_stopped_at)
            )
            ->orderByDesc('located_at')
            ->limit(max(1, min($trailLimit, 1000)))
            ->get(['latitude', 'longitude', 'speed_mph', 'heading_degrees', 'odometer_miles', 'description', 'located_at']);

        /*
        | "Latest known position" from Terminal is, by definition, a ping from
        | at or before the moment it was asked for — it can never postdate the
        | request. eld_tracking_started_at is stamped in the same instant the
        | inline poll on Start runs, so that very first position almost always
        | lands a hair before the cutoff above and the trail query drops it —
        | which would leave the pin blank right when Start's whole point was to
        | put one there immediately. The trail itself stays bounded to the
        | tracking window (that's what keeps a previous broker's route off this
        | load's polyline); only the current-position pin falls back past it.
        */
        $current = $trail->first() ?? EldLocation::query()
            ->where('eld_connection_id', $shipment->eld_connection_id)
            ->where('vehicle_terminal_id', $shipment->eld_vehicle_terminal_id)
            ->when(
                $shipment->eld_tracking_stopped_at,
                fn ($q) => $q->where('located_at', '<=', $shipment->eld_tracking_stopped_at)
            )
            ->orderByDesc('located_at')
            ->first(['latitude', 'longitude', 'speed_mph', 'heading_degrees', 'odometer_miles', 'description', 'located_at']);

        $driver = $shipment->eldDriver;

        return [
            'shipment_uuid' => $shipment->uuid,
            'shipment_no' => $shipment->shipment_no,
            'pro_number' => $shipment->pro_number,

            // What the broker's "Share tracking link" button hands the
            // customer — built here rather than making the frontend
            // reconstruct it, so the base path only ever lives in one place.
            'public_tracking_url' => $shipment->tracking_token
                ? rtrim((string) config('app.frontend_url'), '/').'/track/'.$shipment->tracking_token
                : null,

            'status' => $shipment->status,
            'tracking_started_at' => optional($shipment->eld_tracking_started_at)->toIso8601String(),
            'tracking_stopped_at' => optional($shipment->eld_tracking_stopped_at)->toIso8601String(),

            // How often the poller asks Terminal for this load — the UI's
            // "pings every N min" line reads off the broker's own choice at
            // booking, not a hardcoded number.
            'tracking_interval_seconds' => (int) $shipment->tracking_interval_seconds,

            'origin' => $shipment->origin,
            'destination' => $shipment->destination,
            'carrier_name' => $shipment->carrier_name,
            'carrier_dot' => $shipment->carrier_dot,

            // The underlying ELD provider (Motive, Samsara, Geotab, ...) —
            // Terminal is the aggregator we actually call, this is whose truck
            // it is.
            'eld_provider' => $shipment->eldConnection?->provider,

            'truck_number' => $shipment->truck_number,
            'trailer_number' => $shipment->trailer_number,

            // Falls back to the provider username the same way the fleet
            // dropdown does, for a driver Terminal never got a first/last name
            // for.
            'driver_name' => $driver
                ? (trim($driver->first_name.' '.$driver->last_name) ?: $driver->username)
                : null,

            'current' => $current,

            // Oldest first, which is the order a polyline wants.
            'trail' => $trail->reverse()->values(),
        ];
    }

    private function shipment(string $uuid): Shipment
    {
        return Shipment::with(['eldConnection', 'eldDriver'])
            ->where('uuid', $uuid)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();
    }
}