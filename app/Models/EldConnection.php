<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A telematics account a carrier linked through Terminal.
 *
 * Data-only, like the rest of the onboarding models. The API calls live in
 * App\Services\Eld\TerminalClient and the sync in App\Services\Eld\EldSyncService.
 */
class EldConnection extends Model
{
    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'uuid',
        'terminal_connection_id',
        'connection_token',
        'provider_code',
        'provider_name',
        'status',
        'external_id',
        'account_name',
        'dot_numbers',
        'sync_status',
        'sync_progress',
        'last_synced_at',
        'last_sync_error',
        'connected_at',
        'disconnected_at',
        'payload',
    ];

    /**
     * The token reads the carrier's entire fleet. It is a credential, and no
     * resource has any reason to serialise it.
     */
    protected $hidden = [
        'connection_token',
    ];

    protected $casts = [
        'dot_numbers' => 'array',
        'payload' => 'array',
        'sync_progress' => 'integer',
        'last_synced_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function vehicles()
    {
        return $this->hasMany(EldVehicle::class);
    }

    public function drivers()
    {
        return $this->hasMany(EldDriver::class);
    }

    public function hosLogs()
    {
        return $this->hasMany(EldHosLog::class);
    }

    public function vehicleLocations()
    {
        return $this->hasMany(EldVehicleLocation::class);
    }

    public function connectRequests()
    {
        return $this->hasMany(CarrierConnectRequest::class, 'eld_connection_id');
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
