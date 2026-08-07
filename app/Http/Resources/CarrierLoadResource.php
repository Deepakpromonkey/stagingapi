<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One load as the carrier sees it: what they are hauling, for whom, and every
 * stop on it.
 *
 * Carrier-facing, so this deliberately omits the broker's internal side of the
 * shipment — who created it, the alert email lists, and the tracking schedule
 * the broker set up for their own updates.
 */
class CarrierLoadResource extends JsonResource
{
    public function toArray($request): array
    {
        $stops = $this->stops ?? collect();

        $pickups = $stops->where('stop_type', 'Pickup');
        $deliveries = $stops->where('stop_type', 'Delivery');

        $origin = $pickups->first() ?: $stops->first();
        $destination = $deliveries->last() ?: $stops->last();

        return [
            'uuid' => $this->uuid,
            'shipment_no' => $this->shipment_no,
            'pro_number' => $this->pro_number,
            'status' => $this->status,

            'broker' => [
                'uuid' => $this->company?->uuid,
                'company_name' => $this->company?->company_name,
                'company_phone' => $this->company?->company_phone,
            ],

            // The lane, as a carrier reads it off a rate confirmation.
            'lane' => [
                'origin' => $this->place($origin),
                'destination' => $this->place($destination),
            ],

            'pickup_at' => $origin?->start_date,
            'delivery_at' => $destination?->end_date ?: $destination?->start_date,

            'equipment' => [
                'truck_number' => $this->truck_number,
                'trailer_number' => $this->trailer_number,
                'team_load' => (bool) $this->team_load,
                'driver_type' => $this->driver_type,
            ],

            'tracking' => [
                'method' => $this->tracking_method,
                'number' => $this->tracking_number,
                'started_at' => $this->tracking_start_at?->toIso8601String(),
            ],

            'notes' => $this->notes,

            'stop_count' => $stops->count(),
            'pickup_count' => $pickups->count(),
            'delivery_count' => $deliveries->count(),

            // Every stop on the load, in the order the driver runs them.
            'stops' => $stops->map(fn ($stop) => [
                'stop_number' => $stop->stop_number,
                'stop_type' => $stop->stop_type,
                'name' => $stop->stop_name,
                'address' => $stop->address,
                'address_2' => $stop->address_2,
                'city' => $stop->city,
                'state' => $stop->state,
                'zipcode' => $stop->zipcode,
                'country' => $stop->country,
                'start_date' => $stop->start_date,
                'start_time' => $stop->start_time,
                'start_timezone' => $stop->start_timezone,
                'end_date' => $stop->end_date,
                'end_time' => $stop->end_time,
                'end_timezone' => $stop->end_timezone,

                // What the broker wants the driver to know at this stop.
                'comment_to_driver' => $stop->comment_to_driver,
            ])->values(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * "Dallas, TX" — the shorthand a lane is read in.
     */
    protected function place($stop): ?string
    {
        if (! $stop) {
            return null;
        }

        $place = collect([$stop->city, $stop->state])->filter()->implode(', ');

        return $place ?: ($stop->stop_name ?: $stop->address);
    }
}
