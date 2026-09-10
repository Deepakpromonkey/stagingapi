<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Shipment;
use App\Models\ShipmentMessage;
use Illuminate\Http\Request;

/**
 * The loads a driver was named on, for the app's home screen.
 *
 * Deliberately a narrow projection rather than the broker's shipment payload:
 * a driver needs to identify the load and open its chat, not read the rate or
 * the broker's internal fields.
 */
class DriverShipmentController extends BaseController
{
    public function index(Request $request)
    {
        $driver = $request->user();

        $shipments = Shipment::query()
            ->forDriverPhone($driver->phone_e164)
            ->with(['company:id,company_name', 'stops'])
            ->latest('id')
            ->limit(100)
            ->get();

        // Unread counts for every listed load in one query, rather than one per
        // row while rendering the list.
        $unread = ShipmentMessage::query()
            ->whereIn('shipment_id', $shipments->pluck('id'))
            ->where('sender_type', ShipmentMessage::SENDER_BROKER)
            ->whereNull('read_at')
            ->selectRaw('shipment_id, COUNT(*) as total')
            ->groupBy('shipment_id')
            ->pluck('total', 'shipment_id');

        return $this->success(
            $shipments->map(function (Shipment $shipment) use ($unread) {
                $first = $shipment->stops->first();
                $last = $shipment->stops->last();

                return [
                    'uuid' => $shipment->uuid,
                    'shipment_no' => $shipment->shipment_no,
                    'pro_number' => $shipment->pro_number,
                    'broker' => $shipment->company?->company_name,
                    'carrier_name' => $shipment->carrier_name,
                    'truck_number' => $shipment->truck_number,
                    'trailer_number' => $shipment->trailer_number,
                    'origin' => $first ? trim(($first->city ?? '').' '.($first->state ?? '')) : null,
                    'destination' => $last ? trim(($last->city ?? '').' '.($last->state ?? '')) : null,
                    'unread_messages' => (int) ($unread[$shipment->id] ?? 0),
                ];
            })->values(),
            'Shipments retrieved.'
        );
    }
}
