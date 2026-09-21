<?php
 
namespace App\Http\Requests\Shipment;
 
use Illuminate\Foundation\Http\FormRequest;
 
class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
 
    public function rules(): array
    {
        return [
 
            'pro_number' => [
                'nullable',
                'string',
                'max:100',
            ],
 
            // Carrier
 
            'carrier_name' => [
                'nullable',
                'string',
                'max:255',
            ],
 
            'carrier_mc' => [
                'nullable',
                'string',
                'max:100',
            ],
 
            'carrier_dot' => [
                'nullable',
                'string',
                'max:100',
            ],
 
            'carrier_phone' => [
                'nullable',
                'string',
                'max:30',
            ],
 
            'carrier_extension' => [
                'nullable',
                'string',
                'max:20',
            ],
 
            // Tracking
 
            'tracking_method' => [
                'required',
                'in:driver_phone,eld,gps',
            ],
 
            'country_code' => [
                'nullable',
                'string',
                'max:10',
            ],
 
            'tracking_number' => [
                'nullable',
                'string',
                'max:255',
            ],
 
            // Driver
 
            'truck_number' => [
                'nullable',
                'string',
                'max:100',
            ],
 
            'trailer_number' => [
                'nullable',
                'string',
                'max:100',
            ],
 
            'driver_phone_1' => [
                'nullable',
                'string',
                'max:20',
            ],
 
            'driver_phone_2' => [
                'nullable',
                'string',
                'max:20',
            ],
 
            'driver_phone_3' => [
                'nullable',
                'string',
                'max:20',
            ],
 
            'driver_type' => [
                'nullable',
                'in:company_driver,leased_owner_operator,independent_owner_operator,other_company_driver',
            ],
 
            'team_load' => [
                'boolean',
            ],
 
            'broker_dispatcher_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'broker_dispatcher_email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'tracking_start_at' => [
                'nullable',
                'date',
            ],

            /*
            | Bounded on both ends. Under a minute drains a phone battery inside
            | a shift and buys no useful precision; over six hours is not
            | tracking. Omitted means the column default of 5 minutes.
            */
            'tracking_interval_seconds' => [
                'nullable',
                'integer',
                'min:60',
                'max:21600',
            ],
 
            
            'send_updates_to' => [
                'nullable',
                'array',
            ],
            
            'send_updates_to.*.date_time' => [
                'nullable',
                'date',
            ],
            
            'send_updates_to.*.tracking_days' => [
                'nullable',
                'in:track for 1 day,track for 2 days,track for 3 days,track for 1 week',
            ],
            
            'send_updates_to.*.interval' => [
                'nullable',
                'in:every 15 minutes,every 30 minutes,every 1 hour,every 2 hour',
            ],
            
           
            'email_updates_to' => [
                'nullable',
                'array',
            ],

            'email_updates_to.*' => [
                'email',
            ],
 
            'notes' => [
                'nullable',
                'string',
            ],
 
            'save_as_template' => [
                'nullable',
                'boolean',
            ],
            
            'template_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            
        ];
    }
 
    public function messages(): array
    {
        return [
            'send_updates_to.*.tracking_days.in' => 'Tracking days must be exactly: track for 1 day, track for 2 days, track for 3 days, or track for 1 week.',
            'send_updates_to.*.interval.in' => 'Interval must be exactly: every 15 minutes, every 30 minutes, every 1 hour, or every 2 hour.',
            'email_updates_to.*.email' => 'One or more email addresses provided in the updates list are invalid.',
        ];
    }
}