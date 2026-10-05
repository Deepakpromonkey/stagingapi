<?php

namespace App\Observers;

use App\Events\ShipmentUpdated;
use App\Models\Shipment;
use App\Support\LiveUpdates;

class ShipmentObserver
{
    /** Changes a list of shipments would show. */
    private const LISTED = [
        'status',
        'arrived_at_origin_at',
        'arrived_at_destination_at',
        'eld_tracking_started_at',
        'eld_tracking_stopped_at',
    ];

    /** Changes only a page following this one load cares about. */
    private const TRACKED = ['last_ping_at'];

    public function created(Shipment $shipment): void
    {
        $this->announce($shipment, true);
    }

    public function updated(Shipment $shipment): void
    {
        if ($shipment->wasChanged(self::LISTED)) {
            $this->announce($shipment, true);
        } elseif ($shipment->wasChanged(self::TRACKED)) {
            $this->announce($shipment, false);
        }
    }

    public function deleted(Shipment $shipment): void
    {
        $this->announce($shipment, true);
    }

    private function announce(Shipment $shipment, bool $companyWide): void
    {
        if (! $shipment->uuid || ! $shipment->company_id) {
            return;
        }

        LiveUpdates::send(new ShipmentUpdated(
            (string) $shipment->uuid,
            $shipment->status,
            (int) $shipment->company_id,
            $companyWide,
        ));
    }
}
