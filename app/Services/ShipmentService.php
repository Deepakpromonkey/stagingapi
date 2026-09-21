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

                        $eld = $this->resolveEldSelection($data, $user);

            $shipment = Shipment::create([

                'uuid' => (string) Str::orderedUuid(),

                // What the public tracking link is keyed on — see
                // generateTrackingToken() for why this isn't just the uuid
                // above.
                'tracking_token' => $this->generateTrackingToken(),

                'company_id' => $user->company_id,

                'created_by' => $user->id,

                'updated_by' => $user->id,

                // Shipment
                'shipment_no' => $this->generateShipmentNumber(),

                'pro_number' => $data['pro_number'] ?? null,

                // Carrier
                 'carrier_name' => isset($data['carrier_name'])
                    ? trim($data['carrier_name'])
                    : $eld['carrier_name'],

                'carrier_mc' => isset($data['carrier_mc'])
                    ? strtoupper(trim($data['carrier_mc']))
                    : null,

                'carrier_dot' => isset($data['carrier_dot'])
                    ? strtoupper(trim($data['carrier_dot']))
                    : $eld['carrier_dot'],

                'carrier_phone' => $data['carrier_phone'] ?? null,

                'carrier_extension' => $data['carrier_extension'] ?? null,

                // Tracking
                'tracking_method' => $data['tracking_method'],

                'country_code' => $data['country_code'] ?? null,

                'tracking_number' => $data['tracking_number'] ?? null,


                                // Origin / destination. An ELD load has no trip sheet — the
                // position comes from the truck, so there is nothing for a
                // driver to arrive at and no stop row to arrive there.
                'origin' => trim($data['origin'] ?? '') ?: null,
                'origin_lat' => $data['origin_lat'] ?? null,
                'origin_lng' => $data['origin_lng'] ?? null,
                'destination' => trim($data['destination'] ?? '') ?: null,
                'destination_lat' => $data['destination_lat'] ?? null,
                'destination_lng' => $data['destination_lng'] ?? null,

                // When the load is expected to pick up and deliver. Same
                // shape as a stop's start window — three plain strings, no
                // combined timestamp — because nothing here has enough
                // context to safely fold a date, a time and a timezone name
                // into one instant; whatever reads these back does that.
                'pickup_date' => $data['pickup_date'] ?? null,
                'pickup_time' => $data['pickup_time'] ?? null,
                'pickup_timezone' => $data['pickup_timezone'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                'delivery_time' => $data['delivery_time'] ?? null,
                'delivery_timezone' => $data['delivery_timezone'] ?? null,

                'eld_connection_id' => $eld['connection_id'],
                'eld_vehicle_id' => $eld['vehicle_id'],
                'eld_driver_id' => $eld['driver_id'],
                'eld_vehicle_terminal_id' => $eld['vehicle_terminal_id'],
                'eld_driver_terminal_id' => $eld['driver_terminal_id'],



                // Driver
              'truck_number' => isset($data['truck_number'])
                    ? strtoupper(trim($data['truck_number']))
                    : $eld['truck_number'],

                'trailer_number' => isset($data['trailer_number'])
                    ? strtoupper(trim($data['trailer_number']))
                    : null,

                'driver_phone_1' => $data['driver_phone_1'] ?? $eld['driver_phone'],

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
     * A token for the public tracking link — unguessable, and never the uuid.
     *
     * 48 random characters is far past brute-forcing range, so uniqueness is
     * the only real risk, and it's astronomically small; the loop exists so a
     * collision fails safe instead of racing the unique constraint.
     */
    private function generateTrackingToken(): string
    {
        do {
            $token = Str::random(48);
        } while (Shipment::where('tracking_token', $token)->exists());

        return $token;
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
     * Turn the modal's ELD selection into columns.
     *
     * The denormalised carrier name, truck number and driver phone matter more
     * than they look: every screen downstream of this — the loads list, the
     * mailers, the driver-phone scope on Shipment — reads those columns and
     * knows nothing about telematics. Filling them from the ELD records is what
     * lets an ELD load behave like any other load everywhere else.
     *
     * CreateShipmentRequest::withValidator() has already proved the selection
     * belongs to the caller, but that check and this read are two separate
     * queries — the ownership scope is repeated here rather than trusted
     * blindly, so a future caller that builds $data without going through that
     * FormRequest cannot bind another company's carrier to a shipment.
     */
    private function resolveEldSelection(array $data, $user): array
    {
        $empty = [
            'connection_id' => null,
            'vehicle_id' => null,
            'driver_id' => null,
            'vehicle_terminal_id' => null,
            'driver_terminal_id' => null,
            'carrier_name' => null,
            'carrier_dot' => null,
            'truck_number' => null,
            'driver_phone' => null,
        ];

        if (($data['tracking_method'] ?? null) !== 'eld' || blank($data['eld_connection_uuid'] ?? null)) {
            return $empty;
        }

        $connection = \App\Models\Eld\EldConnection::query()
            ->where('uuid', $data['eld_connection_uuid'])
            ->where('status', \App\Models\Eld\EldConnection::STATUS_CONNECTED)
            ->whereHas('connectRequests', fn ($q) => $q->where('company_id', $user->company_id))
            ->first();

        if (! $connection) {
            throw ValidationException::withMessages([
                'eld_connection_uuid' => ['That carrier is not connected to your account, or the ELD connection is no longer live.'],
            ]);
        }

        $vehicle = $connection->vehicles()
            ->where('terminal_id', $data['eld_vehicle_terminal_id'])
            ->first();

        $driver = $connection->drivers()
            ->where('terminal_id', $data['eld_driver_terminal_id'])
            ->first();

        // The FormRequest confirmed both existed moments ago; a miss here means
        // the fleet changed underneath the submission (a truck deactivated, a
        // driver removed). Failing loudly beats writing a shipment flagged
        // tracking_method=eld with a null vehicle, which scopeEldTracking()
        // would then silently exclude from the poller forever.
        if (! $vehicle) {
            throw ValidationException::withMessages([
                'eld_vehicle_terminal_id' => ["That vehicle is no longer on this carrier's connected fleet. Please reselect it."],
            ]);
        }

        if (! $driver) {
            throw ValidationException::withMessages([
                'eld_driver_terminal_id' => ["That driver is no longer on this carrier's connected fleet. Please reselect them."],
            ]);
        }

        return [
            'connection_id' => $connection->id,
            'vehicle_id' => $vehicle?->id,
            'driver_id' => $driver?->id,
            'vehicle_terminal_id' => $vehicle?->terminal_id,
            'driver_terminal_id' => $driver?->terminal_id,
            'carrier_name' => $connection->carrier_legal_name,
            'carrier_dot' => $connection->carrier_dot_number,

            // Unit number first, plate as the fallback — a broker reads one or
            // the other on a rate confirmation, never the provider's id.
            'truck_number' => $vehicle?->name ?: $vehicle?->license_plate,
            'driver_phone' => $driver?->phone,
        ];
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
            ->with(['stops', 'trackingUpdates', 'eldConnection'])
            ->latest('id')
            ->paginate(15);
    }


}
