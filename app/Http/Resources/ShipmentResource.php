<?php
 
namespace App\Http\Resources;
 
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
 
class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
 
            'uuid' => $this->uuid,
 
            // Shipment
            'shipment_no' => $this->shipment_no,
            'pro_number' => $this->pro_number,
            'status' => $this->status,
 
            // Carrier
            'carrier_name' => $this->carrier_name,
            'carrier_mc' => $this->carrier_mc,
            'carrier_dot' => $this->carrier_dot,
            'carrier_phone' => $this->carrier_phone,
            'carrier_extension' => $this->carrier_extension,
 
            // Tracking
            'tracking_method' => $this->tracking_method,
            'country_code' => $this->country_code,
            'tracking_number' => $this->tracking_number,
 
            // Driver
            'truck_number' => $this->truck_number,
            'trailer_number' => $this->trailer_number,
            'driver_phone_1' => $this->driver_phone_1,
            'driver_phone_2' => $this->driver_phone_2,
            'driver_phone_3' => $this->driver_phone_3,
            'driver_type' => $this->driver_type,
            'team_load' => $this->team_load,

            // Broker dispatcher
            'broker_dispatcher_name' => $this->broker_dispatcher_name,
            'broker_dispatcher_email' => $this->broker_dispatcher_email,

            // Tracking Schedule
            'tracking_start_at' => $this->tracking_start_at,

            // Every scheduled update window, shaped the way Step 1 reads it back.
            'send_updates_to' => $this->trackingUpdates->map(fn ($update) => [
                'date_time' => $update->date_time?->toDateTimeString(),
                'tracking_days' => $update->tracking_days,
                'interval' => $update->interval,
            ])->values(),

            'email_updates_to' => $this->email_updates_to,
 
            
 
            // Notes
            'notes' => $this->notes,
 
            // Audit
            'company_id' => $this->company_id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
 
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
