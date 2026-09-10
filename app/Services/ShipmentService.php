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

                // Broker dispatcher — the person these updates come from
                // The frontend posts "" for an untouched field — keep those NULL
                // so a mailer can trust "has a dispatcher address" checks.
                'broker_dispatcher_name' => trim($data['broker_dispatcher_name'] ?? '') ?: null,

                'broker_dispatcher_email' => trim($data['broker_dispatcher_email'] ?? '') ?: null,

                // Tracking Start
                'tracking_start_at' => $data['tracking_start_at'] ?? null,

                // How often the driver app reports in on this load. The frontend
                // posts "" for an untouched select, which would fail the integer
                // column, so anything blank falls back to five minutes.
                'tracking_interval_seconds' => (int) ($data['tracking_interval_seconds'] ?? 0) ?: 300,

                'email_updates_to' => $this->normaliseEmailList($data['email_updates_to'] ?? null),


                // Notes
                'notes' => isset($data['notes'])
                    ? trim($data['notes'])
                    : null,

                // Status
                'status' => 'draft',

            ]);

            $this->syncTrackingUpdates($shipment, $data['send_updates_to'] ?? []);

            return $shipment->fresh(['trackingUpdates']);
        });
    }

    /**
     * Store every "Send Updates To" row the broker added.
     *
     * The frontend posts a list of rows; rows the broker left completely
     * untouched are skipped so an empty repeater does not create a schedule.
     */
    private function syncTrackingUpdates(Shipment $shipment, $rows): void
    {
        if (! is_array($rows)) {
            return;
        }

        $sequence = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $dateTime = $row['date_time'] ?? null;
            $trackingDays = $row['tracking_days'] ?? null;
            $interval = $row['interval'] ?? null;

            if (blank($dateTime) && blank($trackingDays) && blank($interval)) {
                continue;
            }

            $shipment->trackingUpdates()->create([
                'sequence' => ++$sequence,
                'date_time' => $dateTime ?: null,
                'tracking_days' => $trackingDays ?: null,
                'interval' => $interval ?: null,
            ]);
        }
    }

    /**
     * "" -> null, so an inapplicable field never reaches a typed column.
     *
     * A form posts an empty string for a field the user never filled in. That
     * is fine for a VARCHAR but fatal for DATE/DECIMAL columns under MySQL
     * strict mode, which rejects '' rather than coercing it.
     */
    private function blankToNull($value)
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return ($value === '' || $value === null) ? null : $value;
    }

    /**
     * Normalise an email list into a clean array for storage.
     *
     * Accepts either an array (Step 1's chip input) or a comma/semicolon
     * separated string (Step 2's per-stop alert field). Trims, drops blanks
     * and de-duplicates, and returns null rather than an empty array so the
     * column stays NULL when nothing was entered.
     */
    private function normaliseEmailList($value): ?array
    {
        if (blank($value)) {
            return null;
        }

        $emails = is_array($value)
            ? $value
            : preg_split('/[,;]+/', (string) $value);

        $emails = array_filter(
            array_map(fn ($email) => trim((string) $email), $emails ?: []),
            fn ($email) => $email !== ''
        );

        $emails = array_values(array_unique($emails));

        return $emails ?: null;
    }

    /**
     * The ways a driver can be asked to answer a custom event.
     *
     * Mirrors ANSWER_TYPES in the broker frontend's Step 2. An event the driver
     * cannot be shown a control for is not an event, so anything else falls
     * back to free text rather than being stored and failing later in the app.
     */
    public const ANSWER_TYPES = [
        'yes_no',
        'text',
        'textarea',
        'number',
        'image_upload',
    ];

    /**
     * Normalise the broker's per-stop custom events into what the driver app
     * renders.
     *
     * These are questions the broker wants answered at the stop, not values the
     * broker fills in: the frontend collects `question` and `answer_type`, and
     * the answer arrives later from the driver. Each event gets a stable id so
     * an answer can point at the event it belongs to — the array index cannot
     * do that, because editing the trip sheet rewrites every stop.
     *
     * The `customEventName`/`type` keys are the shape an earlier version of the
     * frontend posted; they are still read so trip sheets saved against it keep
     * their questions.
     */
    private function normaliseCustomEvents($events): ?array
    {
        if (! is_array($events)) {
            return null;
        }

        $normalised = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $question = trim((string) ($event['question'] ?? $event['customEventName'] ?? ''));

            // The repeater starts every new row blank, so a broker who added a
            // row and thought better of it posts one with nothing in it.
            if ($question === '') {
                continue;
            }

            $answerType = (string) ($event['answer_type'] ?? $event['type'] ?? '');

            $normalised[] = [
                'id' => (string) Str::uuid(),
                'question' => $question,
                'answer_type' => in_array($answerType, self::ANSWER_TYPES, true)
                    ? $answerType
                    : 'text',
                'required' => (bool) ($event['required'] ?? true),
            ];
        }

        return $normalised ?: null;
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
            
            $shipment->stops()->delete();

            foreach ($stopsData as $index => $stop) {
                
                $processedEvents = $this->normaliseCustomEvents(
                    $stop['custom_events'] ?? $stop['customEvents'] ?? null
                );

                // 👇 PRODUCTION OTP LOGIC 👇
                // Safely check if frontend sent requires_otp as true
                $requiresOtp = filter_var($stop['requires_otp'] ?? false, FILTER_VALIDATE_BOOLEAN);
                
                // If true, generate a secure 6-digit code. If false, leave it null.
                $otpCode = $requiresOtp ? (string) random_int(100000, 999999) : null;

                $shipment->stops()->create([
                    'stop_number' => $index + 1,
                    'stop_type' => $stop['stop_type'] ?? 'Pickup',
                    'stop_name' => $stop['stop_name'] ?? null,

                    // Where the driver app sends the loading/delivery code
                    'contact_name' => trim($stop['contact_name'] ?? '') ?: null,
                    'contact_phone' => trim($stop['contact_phone'] ?? '') ?: null,


                    // Location
                    'address' => $stop['address'] ?? '',
                    'address_2' => $this->blankToNull($stop['address_2'] ?? null),
                    'city' => $this->blankToNull($stop['city'] ?? null),
                    'state' => $this->blankToNull($stop['state'] ?? null),
                    'zipcode' => $this->blankToNull($stop['zipcode'] ?? null),
                    'country' => $this->blankToNull($stop['country'] ?? null),

                    'latitude' => $this->blankToNull($stop['latitude'] ?? null),
                    'longitude' => $this->blankToNull($stop['longitude'] ?? null),

                    // Timing (Start & End)
                    //
                    // The frontend posts "" for a field that does not apply to
                    // the stop — Pickup has no End window, Delivery has no track
                    // offset. `?? null` does not catch that, and MySQL in strict
                    // mode rejects '' for a DATE column (error 1292), so every
                    // one of these is emptied to NULL explicitly.
                    'start_date' => $this->blankToNull($stop['start_date'] ?? null),
                    'start_time' => $this->blankToNull($stop['start_time'] ?? null),
                    'start_timezone' => $this->blankToNull($stop['start_timezone'] ?? null),
                    'end_date' => $this->blankToNull($stop['end_date'] ?? null),
                    'end_time' => $this->blankToNull($stop['end_time'] ?? null),
                    'end_timezone' => $this->blankToNull($stop['end_timezone'] ?? null),

                    // Comms
                    'comment_to_driver' => $this->blankToNull($stop['comment_to_driver'] ?? null),
                    'alert_emails' => $this->normaliseEmailList($stop['alert_emails'] ?? null),
                    
                    // Events & OTP
                    'events' => !empty($processedEvents) ? $processedEvents : null,
                    'requires_otp' => $requiresOtp,
                    'otp_code' => $otpCode,
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
            ->with(['stops', 'trackingUpdates'])
            ->latest('id') 
            ->paginate(15); 
    }


}
