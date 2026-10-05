<?php

namespace App\Observers;

use App\Events\NotificationsChanged;
use App\Models\CarrierConnectRequest;
use App\Support\LiveUpdates;

/**
 * The notifications feed is built from these rows, so any change to one is a
 * change to its company's bell.
 */
class CarrierConnectRequestObserver
{
    public function saved(CarrierConnectRequest $request): void
    {
        if ($request->company_id) {
            LiveUpdates::send(new NotificationsChanged((int) $request->company_id));
        }
    }
}
