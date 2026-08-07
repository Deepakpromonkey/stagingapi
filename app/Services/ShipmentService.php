<?php

namespace App\Services;

use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;

class ShipmentService
{
    /**
     * Create Shipment
     */
    public function create(array $data, $user)
    {
        return DB::transaction(function () use ($data, $user) {

            // Prevent duplicate Tracking Number within the same company
            if (
                ! empty($data['tracking_number']) &&
                Shipment::where('company_id', $user->company_id)
                    ->where('tracking_number', $data['tracking_number'])
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'tracking_number' => [
                        'Tracking number already exists.',
                    ],
                ]);
            }

            $shipment = Shipment::create([

                'uuid' => (string) Str::orderedUuid(),

                'company_id' => $user->company_id,

                'created_by' => $user->id,

                'updated_by' => $user->id,

                // Shipment
                'shipment_no' => $this->generateShipmentNumber(),

                'pro_number' => $data['pro_number'] ?? null,

                // Carrier
                'carrier_name' => isset($data['carrier_name'])
                    ? trim($data['carrier_name'])
                    : null,

                'carrier_mc' => isset($data['carrier_mc'])
                    ? strtoupper(trim($data['carrier_mc']))
                    : null,

                'carrier_dot' => isset($data['carrier_dot'])
                    ? strtoupper(trim($data['carrier_dot']))
                    : null,

                'carrier_phone' => $data['carrier_phone'] ?? null,

                'carrier_extension' => $data['carrier_extension'] ?? null,

                // Tracking
                'tracking_method' => $data['tracking_method'],

                'country_code' => $data['country_code'] ?? null,

                'tracking_number' => $data['tracking_number'] ?? null,

                // Driver
                'truck_number' => isset($data['truck_number'])
                    ? strtoupper(trim($data['truck_number']))
                    : null,

                'trailer_number' => isset($data['trailer_number'])
                    ? strtoupper(trim($data['trailer_number']))
                    : null,

                'driver_phone_1' => $data['driver_phone_1'] ?? null,

                'driver_phone_2' => $data['driver_phone_2'] ?? null,

                'driver_phone_3' => $data['driver_phone_3'] ?? null,

                'driver_type' => $data['driver_type'] ?? null,

                'team_load' => $data['team_load'] ?? false,

                // Tracking Start
                'tracking_start_at' => $data['tracking_start_at'] ?? null,

                'update_datetime' => $data['send_updates_to']['date_time'] ?? null,
                'tracking_days' => $data['send_updates_to']['tracking_days'] ?? null,
                'tracking_interval' => $data['send_updates_to']['interval'] ?? null,
                'email_updates_to' => $data['email_updates_to'] ?? null,


                // Notes
                'notes' => isset($data['notes'])
                    ? trim($data['notes'])
                    : null,

                // Status
                'status' => 'draft',

            ]);

            return $shipment->fresh();
        });
    }

    /**
     * Generate Shipment Number
     *
     * Example:
     * SHP-2026-000001
     */
    private function generateShipmentNumber(): string
    {
        $lastShipment = Shipment::latest('id')->first();

        $nextNumber = $lastShipment
            ? $lastShipment->id + 1
            : 1;

        return 'SHP-'.
            date('Y').
            '-'.
            str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }


    /**
     * Add Stops to an existing Shipment
     */
    public function addStops(Shipment $shipment, array $stopsData, \Illuminate\Http\Request $request)
    {
        return DB::transaction(function () use ($shipment, $stopsData, $request) {
            
            $pickupStop = collect($stopsData)->firstWhere(function ($stop) {
                return strcasecmp(trim($stop['stop_type'] ?? ''), 'Pickup') === 0;
            });
            
            if ($pickupStop && !empty($pickupStop['track_start_date']) && !empty($pickupStop['track_start_time'])) {
                $tzMap = [
                    'IST' => 'Asia/Kolkata',
                    'UTC' => 'UTC'
                ];

                $timezone = $tzMap[$pickupStop['start_timezone'] ?? 'IST'] ?? 'UTC';
                $datetimeString = trim($pickupStop['track_start_date']) . ' ' . trim($pickupStop['track_start_time']);
                
                $shipment->tracking_start_at = \Carbon\Carbon::createFromFormat('Y-m-d h:i A', $datetimeString, $timezone)->setTimezone('UTC');
                $shipment->save();
            }
            // -------------------------------------------------

            $shipment->stops()->delete();

            foreach ($stopsData as $index => $stop) {
                
                $stopType = $stop['stop_type'] ?? null;

                if (!$stopType) {
                    throw ValidationException::withMessages([
                        'stops_data' => ["Stop #".($index + 1)." is missing the compulsory 'stop_type' field."],
                    ]);
                }

                if (strcasecmp(trim($stopType), 'Pickup') === 0) {
                    $stop['end_date'] = null;
                    $stop['end_time'] = null;
                    $stop['end_timezone'] = null;
                }

                $processedEvents = [];
                if (isset($stop['custom_events']) && is_array($stop['custom_events'])) {
                    foreach ($stop['custom_events'] as $event) {
                        
                        $eventData = [
                            'customEventName' => $event['customEventName'] ?? 'Event',
                            'type' => $event['type'] ?? 'text',
                            'value' => $event['value'] ?? null,
                        ];

                        if ($eventData['type'] === 'file' && !empty($event['file_key'])) {
                            $fileKey = $event['file_key'];
                            
                            if ($request->hasFile($fileKey)) {
                                $file = $request->file($fileKey);
                                $path = $file->store('shipments/' . $shipment->uuid . '/events', 's3');
                                $eventData['value'] = Storage::disk('s3')->url($path);
                            }
                        }

                        $processedEvents[] = $eventData;
                    }
                }

                $shipment->stops()->create([
                    'stop_number' => $index + 1,
                    'stop_type' => trim($stopType), 
                    'stop_name' => $stop['stop_name'] ?? null,
                    
                    'address' => $stop['address'] ?? '',
                    'address_2' => $stop['address_2'] ?? null,
                    'city' => $stop['city'] ?? null,
                    'state' => $stop['state'] ?? null,
                    'zipcode' => $stop['zipcode'] ?? null,
                    'country' => $stop['country'] ?? null,

                    'latitude' => $stop['latitude'] ?? null,
                    'longitude' => $stop['longitude'] ?? null,
                    
                    'start_date' => $stop['start_date'] ?? null,
                    'start_time' => $stop['start_time'] ?? null,
                    'start_timezone' => $stop['start_timezone'] ?? null,
                    'end_date' => $stop['end_date'] ?? null,
                    'end_time' => $stop['end_time'] ?? null,
                    'end_timezone' => $stop['end_timezone'] ?? null,
                    
                    'comment_to_driver' => $stop['comment_to_driver'] ?? null,
                    'alert_emails' => $stop['alert_emails'] ?? null,
                    
                    'events' => !empty($processedEvents) ? $processedEvents : null,
                ]);
            }

            return $shipment->load('stops');
        });
    }


    /**
     * Get all shipments for the authenticated user's company
     */
    public function getAllForUser($user)
    {
        return Shipment::where('company_id', $user->company_id)
            ->with('stops') 
            ->latest('id') 
            ->paginate(15); 
    }


}
