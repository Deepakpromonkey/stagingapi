<?php

namespace App\Models\Eld;

use App\Models\CarrierConnectRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A carrier's telematics connection, held through Terminal.
 *
 * Data-only, like CarrierConnectRequest: the rules for minting a Link URL,
 * exchanging a public token and deduping a second broker's connect attempt live
 * in App\Services\Eld\EldConnectionService.
 */
class EldConnection extends Model
{
    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ARCHIVED = 'archived';

    public const SYNC_PENDING = 'pending';

    public const SYNC_RUNNING = 'running';

    public const SYNC_COMPLETED = 'completed';

    public const SYNC_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'carrier_dot_number',
        'carrier_row_id',
        'carrier_legal_name',
        'terminal_connection_id',
        'external_id',
        'provider',
        'connection_token',
        'status',
        'sync_status',
        'last_sync_at',
        'last_sync_error',
        'vehicle_count',
        'driver_count',
        'connected_at',
        'disconnected_at',
        'archived_at',
    ];

    /*
    | The connection token is the carrier's provider access in a string. It is
    | encrypted at rest and hidden here as well, so a caller that serialises the
    | model directly — bypassing every resource — still cannot leak it.
    */
    protected $hidden = [
        'connection_token',
    ];

    protected $casts = [
        'connection_token' => 'encrypted',
        'last_sync_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function grants(): HasMany
    {
        return $this->hasMany(EldConnectionGrant::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(EldVehicle::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(EldDriver::class);
    }

    public function hosLogs(): HasMany
    {
        return $this->hasMany(EldHosLog::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(EldLocation::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(EldSyncCheckpoint::class);
    }

    public function connectRequests(): HasMany
    {
        return $this->hasMany(CarrierConnectRequest::class);
    }

    /**
     * Whether this connection may still be synced.
     *
     * Archived keeps its history and stops the meter; disconnected means the
     * carrier's provider credentials no longer work and re-auth is the only way
     * back. Neither should be polled.
     */
    public function isSyncable(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
