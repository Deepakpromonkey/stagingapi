<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Eld\EldLocation;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The page a customer opens with nothing but a link — no login, no company
 * context, nothing proving who they are. That makes this the one controller
 * in the whole API a stranger can call, so it is deliberately narrow: it
 * answers with a token, not a uuid (see the tracking_token migration for why
 * that split matters), and it never returns anything that identifies the
 * carrier, the driver, or this broker's internal ids — only what a shipper
 * needs in order to know where their freight is. That guarantee applies
 * identically to every tracking_method — a driver_phone load's GPS trail
 * comes off driver_locations, but the field selection there is exactly as
 * tight as EldLocation's below, on purpose.
 *
 * `state` is the coarse status (pending/in_transit/delivered/cancelled);
 * `milestone` is the finer-grained stage within in_transit — one of
 * Shipment::MILESTONES, via Shipment::currentMilestone() (see there for why
 * arrived_at_origin/destination only populate for a load whose origin and
 * destination were picked from the map autocomplete, not typed by hand, on
 * the ELD path). The same method backs the broker's own /eld/track endpoint
 * for ELD loads, so the two views can never disagree about what stage an
 * ELD load is in.
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

        // Milestone data goes out on every non-cancelled state, delivered
        // included — a completed load's whole point on this page is showing
        // the finished journey, all four steps checked off, not just the
        // fact that it's done.
        //
        // arrived_at_origin_at / arrived_at_destination_at stay ELD-only
        // below on purpose — those two columns are only ever written by
        // EldTrackingService's geofencing pass, so a driver_phone load has
        // nothing real to put there. `milestone` alone (from
        // currentMilestone(), which does read the driver-app's own
        // timestamps for that path) is what the page actually renders the
        // stepper from - see EldMilestones.jsx.
        $base += [
            'milestone' => $shipment->currentMilestone(),
            'arrived_at_origin_at' => $shipment->tracking_method === 'eld'
                ? optional($shipment->arrived_at_origin_at)->toIso8601String()
                : null,
            'arrived_at_destination_at' => $shipment->tracking_method === 'eld'
                ? optional($shipment->arrived_at_destination_at)->toIso8601String()
                : null,
        ];

        if ($shipment->status === 'completed') {
            return $base + [
                'state' => 'delivered',
                'delivered_at' => optional(
                    $shipment->eld_tracking_stopped_at ?? $shipment->updated_at
                )->toIso8601String(),
            ];
        }

        return $base + [
            'state' => $this->isInTransit($shipment) ? 'in_transit' : 'pending',

            // What the page's own poll rate is paced against — no reason to
            // ask more often than the broker's own chosen tracking
            // frequency could possibly produce something new.
            'tracking_interval_seconds' => (int) $shipment->tracking_interval_seconds,

            'current' => $this->currentPosition($shipment),
        ];
    }

    /**
     * ELD loads keep the exact existing check - a load whose poller has
     * actually been started, not merely "active" in status (the two can be
     * briefly out of step around Start/Stop). Everything else has no such
     * column to check, so status itself - which the broker's own dispatch
     * action sets - is the only signal there is.
     */
    private function isInTransit(Shipment $shipment): bool
    {
        if ($shipment->tracking_method === 'eld') {
            return (bool) $shipment->eld_tracking_started_at;
        }

        return $shipment->status === 'active';
    }

    /**
     * The live position, read from whichever source this shipment's
     * tracking method actually writes to.
     *
     * ELD loads: unchanged, EldLocation exactly as before.
     *
     * Everything else: the driver app's own driver_locations table (a
     * separate app's table in this same database — see
     * DriverActivityService's docblock), latest row by shipment_uuid.
     * Field selection is deliberately as tight as the ELD branch's: lat,
     * lng, a timestamp, nothing that names the driver.
     *
     * A load on either path with nothing to report returns null rather
     * than erroring — "no position available" is a normal state for this
     * page, not a fault. A query against a table that does not exist in
     * this environment (the driver app's migrations never ran here, e.g.
     * in tests) degrades the same way rather than 500ing a public page.
     */
    private function currentPosition(Shipment $shipment): ?array
    {
        if ($shipment->tracking_method === 'eld') {

            if (! $shipment->eld_connection_id || ! $shipment->eld_vehicle_terminal_id) {
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

        if (! $shipment->uuid) {
            return null;
        }

        try {
            $ping = DB::table('driver_locations')
                ->where('shipment_uuid', $shipment->uuid)
                ->orderByDesc('id')
                ->first(['lat', 'lng', 'created_at']);
        } catch (\Throwable $e) {
            Log::warning('[PublicTracking] driver_locations lookup failed', [
                'shipment_uuid' => $shipment->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $ping) {
            return null;
        }

        return [
            'latitude' => (float) $ping->lat,
            'longitude' => (float) $ping->lng,
            'description' => null,

            // A raw DB::table() row hands back created_at as a plain
            // string, not a Carbon instance - Eloquent's date casting is
            // what usually does that conversion, and there is no model
            // here to do it.
            'last_updated_at' => $ping->created_at
                ? \Illuminate\Support\Carbon::parse($ping->created_at)->toIso8601String()
                : null,
        ];
    }
}
