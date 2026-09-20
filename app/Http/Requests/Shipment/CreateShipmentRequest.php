<?php

namespace App\Http\Requests\Shipment;

use App\Models\Eld\EldConnection;
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

            /*
            | Origin and destination.
            |
            | Required on an ELD load and only on an ELD load: it has no trip
            | sheet — the position comes from the truck, so there is no stop for
            | a driver to arrive at — and these two strings are the only record
            | of where the freight was going. A phone-tracked load gets the same
            | information from its stops.
            */
            'origin' => [
                'required_if:tracking_method,eld',
                'nullable',
                'string',
                'max:255',
            ],

            'destination' => [
                'required_if:tracking_method,eld',
                'nullable',
                'string',
                'max:255',
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

            /*
            | ELD binding — which carrier's connection, and which truck and
            | driver on it.
            |
            | Required together or not at all: a load flagged as ELD-tracked
            | with no truck behind it sits in the poller's scope forever
            | reporting nothing.
            |
            | These carry Terminal's own ids rather than our row ids, because
            | that is what the fleet endpoint handed the modal. Ownership is
            | proved in withValidator() below — the rules here only establish
            | shape, and a `max` that fits Terminal's prefixed ULIDs.
            */
            'eld_connection_uuid' => [
                'required_if:tracking_method,eld',
                'nullable',
                'string',
                'max:64',
            ],

            'eld_vehicle_terminal_id' => [
                'required_if:tracking_method,eld',
                'nullable',
                'string',
                'max:64',
            ],

            'eld_driver_terminal_id' => [
                'required_if:tracking_method,eld',
                'nullable',
                'string',
                'max:64',
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
            |
            | On an ELD load the battery argument does not apply, but the floor
            | still matters for a different reason: this is how often the poller
            | calls Terminal for that truck, and Terminal bills per call.
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

    /**
     * Prove the ELD selection belongs to the caller before it is stored.
     *
     * Without this, a connection uuid guessed or lifted from another broker's
     * response would bind this company's load to a carrier they never onboarded
     * — and, worse, would start polling that carrier's truck positions on their
     * behalf, on their Terminal meter.
     *
     * The three checks are separate so the broker is told which part of the
     * selection went stale, which is the usual cause of a failure here: a truck
     * deactivated between the dropdown opening and the form being submitted.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('tracking_method') !== 'eld') {
                return;
            }

            /*
            | The route is behind auth:sanctum, so this holds in production.
            | Guarded anyway because a FormRequest is also exercised in tests
            | and by any future unauthenticated path, where a null user here is
            | a fatal error rather than a validation failure.
            */
            $companyId = auth()->user()?->company_id;

            if (! $companyId) {
                $validator->errors()->add(
                    'eld_connection_uuid',
                    'You are not authorised to book ELD-tracked loads.'
                );

                return;
            }

            $connection = EldConnection::query()
                ->where('uuid', $this->input('eld_connection_uuid'))
                ->where('status', EldConnection::STATUS_CONNECTED)
                ->whereHas('connectRequests', fn ($q) => $q->where('company_id', $companyId))
                ->first();

            if (! $connection) {
                $validator->errors()->add(
                    'eld_connection_uuid',
                    'That carrier is not connected to your account, or the ELD connection is no longer live.'
                );

                // No point checking a fleet we have not established is ours.
                return;
            }

            if (! $connection->vehicles()->where('terminal_id', $this->input('eld_vehicle_terminal_id'))->exists()) {
                $validator->errors()->add(
                    'eld_vehicle_terminal_id',
                    "That vehicle is not on this carrier's connected fleet."
                );
            }

            if (! $connection->drivers()->where('terminal_id', $this->input('eld_driver_terminal_id'))->exists()) {
                $validator->errors()->add(
                    'eld_driver_terminal_id',
                    "That driver is not on this carrier's connected fleet."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'send_updates_to.*.tracking_days.in' => 'Tracking days must be exactly: track for 1 day, track for 2 days, track for 3 days, or track for 1 week.',
            'send_updates_to.*.interval.in' => 'Interval must be exactly: every 15 minutes, every 30 minutes, every 1 hour, or every 2 hour.',
            'email_updates_to.*.email' => 'One or more email addresses provided in the updates list are invalid.',

            'origin.required_if' => 'Origin is required for an ELD-tracked load.',
            'destination.required_if' => 'Destination is required for an ELD-tracked load.',
            'eld_connection_uuid.required_if' => 'Select a connected carrier for an ELD-tracked load.',
            'eld_vehicle_terminal_id.required_if' => 'Select a vehicle for an ELD-tracked load.',
            'eld_driver_terminal_id.required_if' => 'Select a driver for an ELD-tracked load.',
        ];
    }
}