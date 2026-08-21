<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
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
    ];

    protected $casts = [
        'team_load' => 'boolean',
        'tracking_start_at' => 'datetime',
        'tracking_interval_seconds' => 'integer',
        // TELL LARAVEL TO HANDLE THIS AS AN ARRAY
        'email_updates_to' => 'array',
        'last_ping_at' => 'datetime',
        'last_alert_sent_at' => 'datetime',
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
}