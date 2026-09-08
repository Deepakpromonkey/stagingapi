<?php

namespace App\Events;

use App\Models\ShipmentMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a new message to the other side of a shipment's chat.
 *
 * One private channel per shipment, which both the broker's staff and the named
 * driver join. Who may listen is decided in routes/channels.php — the channel
 * name contains an id, so without that check anyone could subscribe to any
 * shipment's conversation by guessing a number.
 */
class ShipmentMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ShipmentMessage $message;

    public function __construct(ShipmentMessage $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        // Keyed on the uuid rather than the row id: the clients already hold
        // the uuid, and a channel name built from a sequential id both leaks
        // how many shipments exist and is trivial to enumerate.
        return [
            new PrivateChannel('shipment.'.$this->message->shipment->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * The same shape the REST endpoints return, so a client renders a live
     * message and a reloaded one through identical code.
     */
    public function broadcastWith(): array
    {
        return ['message' => $this->message->toWire()];
    }
}
