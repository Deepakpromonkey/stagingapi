<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The header bell's feed changed for a company: refetch /notifications.
 *
 * The feed is derived from carrier onboarding rows rather than stored, so the
 * signal is "look again", with nothing in the payload.
 */
class NotificationsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly string $companyUuid) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('company.'.$this->companyUuid)];
    }

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
