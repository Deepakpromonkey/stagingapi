<?php

use App\Models\Driver;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
| The broker/driver chat for one shipment.
|
| Both audiences authenticate through the same Sanctum guard, so whoever
| subscribes here may be a broker User or a Driver — and the channel name
| carries a shipment id, which anyone could guess. This callback is therefore
| the only thing standing between a subscriber and someone else's conversation,
| and it answers for each audience separately:
|
|   - a broker may listen to their own company's shipments
|   - a driver may listen to shipments their phone number was named on
|
| Anything else — a carrier portal token, a driver who has been deactivated —
| falls through to false and is refused.
*/
Broadcast::channel('shipment.{shipmentUuid}', function ($user, $shipmentUuid) {
    $shipment = Shipment::where('uuid', $shipmentUuid)->first();

    if (! $shipment) {
        return false;
    }

    if ($user instanceof User) {
        return (int) $user->company_id === (int) $shipment->company_id;
    }

    if ($user instanceof Driver) {
        if (! $user->is_active) {
            return false;
        }

        return Shipment::query()
            ->whereKey($shipment->getKey())
            ->forDriverPhone($user->phone_e164)
            ->exists();
    }

    return false;
});
