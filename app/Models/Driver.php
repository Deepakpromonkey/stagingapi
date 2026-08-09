<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A driver, authenticating from the driver app with their phone number.
 *
 * Not a User and not a CarrierUser: a driver holds no seat in a broker company
 * and no role in the carrier portal. They authenticate through the same Sanctum
 * guard as everyone else, so every driver token carries the `driver-app`
 * ability and EnsureDriver keeps a driver token out of the broker and carrier
 * routes — the same arrangement CarrierUser already uses.
 *
 * There are deliberately no roles or permissions here. A driver can reach
 * exactly the shipments their phone number appears on, which is a data question
 * rather than a permission one.
 */
class Driver extends Authenticatable
{
    use HasApiTokens;

    /** Ability stamped on every driver token. */
    public const TOKEN_ABILITY = 'driver-app';

    protected $fillable = [
        'uuid',
        'phone_e164',
        'name',
        'phone_verified_at',
        'last_login_at',
        'last_login_ip',
        'is_active',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Reduces a phone number to the form stored in `phone_e164`, so the number
     * a broker typed and the one a driver signed in with resolve to the same
     * driver.
     *
     * A ten digit number is assumed to be the configured default country, which
     * matches how carrier phone numbers are already handled.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        // Already international, written with a leading + or 00.
        if (str_starts_with(trim((string) $phone), '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            $dial = preg_replace('/\D+/', '', (string) config('carrier_connect.default_dial_code', '+1'));

            return '+'.$dial.$digits;
        }

        return '+'.$digits;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ShipmentMessage::class, 'sender_id')
            ->where('sender_type', ShipmentMessage::SENDER_DRIVER);
    }

    /**
     * The shipments this driver was named on.
     *
     * Matched on the phone number the broker typed, across all three driver
     * slots. This is the whole of a driver's access: no shipment they are not
     * named on is reachable.
     */
    public function shipments()
    {
        return Shipment::query()->forDriverPhone($this->phone_e164);
    }
}
