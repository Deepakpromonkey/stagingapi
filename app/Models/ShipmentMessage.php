<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a shipment's broker/driver chat.
 */
class ShipmentMessage extends Model
{
    public const SENDER_BROKER = 'broker';

    public const SENDER_DRIVER = 'driver';

    protected $fillable = [
        'uuid',
        'shipment_id',
        'sender_type',
        'sender_id',
        'sender_name',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function isFrom(string $senderType, int $senderId): bool
    {
        return $this->sender_type === $senderType
            && (int) $this->sender_id === $senderId;
    }

    /**
     * The wire shape, shared by the REST list, the send response and the
     * broadcast payload.
     *
     * One method rather than three so a client can render a message identically
     * whether it arrived over the socket or in a page load — if these drifted,
     * a live message would render differently from the same message after a
     * refresh.
     */
    public function toWire(): array
    {
        return [
            'uuid' => $this->uuid,
            'shipment_id' => $this->shipment_id,
            'sender_type' => $this->sender_type,
            'sender_id' => (int) $this->sender_id,
            'sender_name' => $this->sender_name,
            'body' => $this->body,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
