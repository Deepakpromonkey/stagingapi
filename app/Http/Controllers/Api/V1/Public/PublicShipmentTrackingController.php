<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Eld\EldLocation;
use App\Models\Shipment;

/**
 * The page a customer opens with nothing but a link — no login, no company
 * context, nothing proving who they are. That makes this the one controller
 * in the whole API a stranger can call, so it is deliberately narrow: it
 * answers with a token, not a uuid (see the tracking_token migration for why
 * that split matters), and it never returns anything that identifies the
 * carrier, the driver, or this broker's internal ids — only what a shipper
 * needs in order to know where their freight is.
 *
 * "Picked up" as its own step does not exist here on purpose, not by
 * omission. Distinguishing it from "in transit" needs the truck's position
 * checked against the origin's coordinates, and origin is a free-text string
 * ("Dallas, TX") with no coordinates behind it — there is nothing to geofence
 * against yet. Faking a step neither side of the API can actually tell apart
 * would be worse than not having it. Three real states are returned —
 * pending, in_transit, delivered — plus cancelled; a fourth can follow once
 * origin/destination are geocoded.
 */
class PublicShipmentTrackingController extends BaseController
{
    public function show(string $token)
    {
        $shipment = Shipment::where('tracking_token', $token)->first();

        /*
        | Same message and status whether the token never existed or simply
        | doesn't match anything. A response that told the two apart would let
        | someone try candidate tokens and watch which error changes — a way
        | to probe for a real one without ever being right.
        */
        if (! $shipment) {
            return $this->error('This tracking link is invalid or has expired.', null, 404);
        }

        return $this->success($this->payloadFor($shipment), 'Tracking retrieved successfully.');
    }

    private function payloadFor(Shipment $shipment): array
    {
        $base = [
            'shipment_no' => $shipment->shipment_no,
            'origin' => $shipment->origin,
            'destination' => $shipment->destination,
        ];

        if ($shipment->status === 'cancelled') {
            return $base + ['state' => 'cancelled'];
        }

        if ($shipment->status === 'completed') {
            return $base + [
                'state' => 'delivered',
                'delivered_at' => optional(
                    $shipment->eld_tracking_stopped_at ?? $shipment->updated_at
                )->toIso8601String(),
            ];
        }

        return $base + [
            'state' => $shipment->eld_tracking_started_at ? 'in_transit' : 'pending',
            'current' => $this->currentPosition($shipment),
        ];
    }

    /**
     * Only ELD loads have anywhere to read a position from right now —
     * driver-phone tracking has no live location write path yet. A load on
     * that method returns null here rather than erroring, same as an ELD load
     * that hasn't reported in yet: "no position available" is a normal state
     * for this page, not a fault.
     */
    private function currentPosition(Shipment $shipment): ?array
    {
        if ($shipment->tracking_method !== 'eld'
            || ! $shipment->eld_connection_id
            || ! $shipment->eld_vehicle_terminal_id) {
            return null;
        }

        $location = EldLocation::query()
            ->where('eld_connection_id', $shipment->eld_connection_id)
            ->where('vehicle_terminal_id', $shipment->eld_vehicle_terminal_id)
            ->orderByDesc('located_at')
            ->first(['latitude', 'longitude', 'description', 'located_at']);

        if (! $location) {
            return null;
        }

        return [
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'description' => $location->description,
            'last_updated_at' => optional($location->located_at)->toIso8601String(),
        ];
    }
}
