<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'tracking_token',
        'company_id',
        'created_by',
        'updated_by',
        'shipment_no',
        'pro_number',
        'carrier_name',
        'carrier_mc',
        'carrier_dot',
        'carrier_phone',
        'carrier_extension',
        'tracking_method',
        'country_code',
        'tracking_number',
        'truck_number',
        'trailer_number',
        'driver_phone_1',
        'driver_phone_2',
        'driver_phone_3',
        'driver_type',
        'team_load',
        'broker_dispatcher_name',
        'broker_dispatcher_email',
        'tracking_start_at',
        'tracking_interval_seconds',
        'email_updates_to',
        'notes',
        'status',
        'origin',
    'origin_lat',
    'origin_lng',
    'destination',
    'destination_lat',
    'destination_lng',
    'pickup_date',
    'pickup_time',
    'pickup_timezone',
    'delivery_date',
    'delivery_time',
    'delivery_timezone',
    'eld_connection_id',
    'eld_vehicle_id',
    'eld_driver_id',
    'eld_vehicle_terminal_id',
    'eld_driver_terminal_id',
    'eld_tracking_started_at',
    'eld_tracking_stopped_at',
    ];

    protected $casts = [
        'team_load' => 'boolean',
        'tracking_start_at' => 'datetime',
        'pickup_date' => 'date',
        'delivery_date' => 'date',
        'origin_lat' => 'float',
        'origin_lng' => 'float',
        'destination_lat' => 'float',
        'destination_lng' => 'float',
        'tracking_interval_seconds' => 'integer',
        // TELL LARAVEL TO HANDLE THIS AS AN ARRAY
        'email_updates_to' => 'array',
        'last_ping_at' => 'datetime',
        'last_alert_sent_at' => 'datetime',
        'eld_tracking_started_at' => 'datetime',
        'eld_tracking_stopped_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function stops()
    {
        return $this->hasMany(ShipmentStop::class)->orderBy('stop_number');
    }

    /** The "Send Updates To" schedule rows, in the order the broker added them. */
    public function trackingUpdates()
    {
        return $this->hasMany(ShipmentTrackingUpdate::class)->orderBy('sequence');
    }

    /** The broker/driver chat for this load, oldest first. */
    public function messages()
    {
        return $this->hasMany(ShipmentMessage::class)->orderBy('id');
    }

    /**
     * Shipments a given driver phone was named on.
     *
     * This is the entirety of a driver's reach: the broker typed their number
     * into one of the three driver slots, and that is what grants access. The
     * comparison is made digits-only so formatting differences between what the
     * broker typed and what the driver signed in with cannot hide a load.
     */
    public function scopeForDriverPhone($query, ?string $phone)
    {
        $normalised = Driver::normalisePhone($phone);

        if (! $normalised) {
            // Never fall through to "every shipment" on a missing number.
            return $query->whereRaw('1 = 0');
        }

        $digits = ltrim($normalised, '+');

        return $query->where(function ($inner) use ($digits) {
            foreach (['driver_phone_1', 'driver_phone_2', 'driver_phone_3'] as $column) {
                $inner->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', '') LIKE ?",
                    ['%'.$digits]
                );
            }
        });
    }

  public function eldConnection()
{
    return $this->belongsTo(\App\Models\Eld\EldConnection::class, 'eld_connection_id');
}

public function eldVehicle()
{
    return $this->belongsTo(\App\Models\Eld\EldVehicle::class, 'eld_vehicle_id');
}

public function eldDriver()
{
    return $this->belongsTo(\App\Models\Eld\EldDriver::class, 'eld_driver_id');
}

/**
 * Loads the ELD poller is responsible for.
 *
 * Started and not yet stopped. A draft has no vehicle bound to it and a
 * delivered load's truck has moved on to somebody else's freight — polling
 * either would be paying Terminal for a position nobody is watching.
 */
public function scopeEldTracking($query)
{
    return $query->where('tracking_method', 'eld')
        ->whereNotNull('eld_connection_id')
        ->whereNotNull('eld_vehicle_terminal_id')
        ->whereNotNull('eld_tracking_started_at')
        ->whereNull('eld_tracking_stopped_at');
}

}