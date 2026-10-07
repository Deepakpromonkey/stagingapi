<?php

namespace App\Models\Eld;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far each resource's sync has read.
 *
 * `synced_through` is Terminal's clock, never ours: it is the newest ingestion
 * timestamp the last pass actually saw, so a slow provider cannot cause a
 * window to be skipped because our own wall clock moved on.
 */
class EldSyncCheckpoint extends Model
{
    public const RESOURCES = ['vehicles', 'drivers', 'hos', 'locations'];

    protected $fillable = [
        'eld_connection_id',
        'resource',
        'synced_through',
        'last_run_at',
        'last_record_count',
    ];

    protected $casts = [
        'synced_through' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EldConnection::class, 'eld_connection_id');
    }
}
