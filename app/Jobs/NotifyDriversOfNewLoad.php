<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Services\Shipment\LoadAssignmentNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the drivers on a just-booked load about it - see
 * LoadAssignmentNotifier for what each driver gets.
 *
 * Dispatched ->afterResponse() by ShipmentController::store(): the broker's
 * booking returns first, and a slow or failing SMS / push gateway can never
 * hold it up or turn it into an error. Same pattern as
 * ScoreCarrierSearchPage.
 */
class NotifyDriversOfNewLoad implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $phones  International numbers, from LoadAssignmentNotifier::phonesFor().
     */
    public function __construct(
        public int $shipmentId,
        public array $phones,
    ) {}

    public function handle(LoadAssignmentNotifier $notifier): void
    {
        $shipment = Shipment::with('company')->find($this->shipmentId);

        if ($shipment) {
            $notifier->notify($shipment, $this->phones);
        }
    }
}
