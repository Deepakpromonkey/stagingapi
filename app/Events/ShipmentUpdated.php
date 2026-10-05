<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells open pages that a shipment changed, so they refetch instead of polling.
 *
 * A signal, not the data: the payload is the uuid and status, and whoever
 * cares reloads through the normal, authorised endpoint. Nothing the REST API
 * would hide can leak through the socket that way.
 *
 * Always on the shipment's own channel (its detail and tracking pages). On the
 * company channel too when the change is one a list would show — a new
 * position alone is not, and arrives every minute for every ELD load.
 */
class ShipmentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $uuid,
        public readonly ?string $status,
        public readonly int $companyId,
        public readonly bool $companyWide,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('shipment.'.$this->uuid)];

        if ($this->companyWide) {
            $channels[] = new PrivateChannel('company.'.$this->companyId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'shipment.updated';
    }

    public function broadcastWith(): array
    {
        return ['uuid' => $this->uuid, 'status' => $this->status];
    }
}
