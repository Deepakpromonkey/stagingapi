<?php

namespace App\Http\Controllers\Api\V1\Eld;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Eld\EldConnection;
use App\Services\Eld\EldSyncService;
use Illuminate\Http\Request;

/**
 * What the "New live tracking" modal reads.
 *
 * Both endpoints answer from our own tables rather than from Terminal. The
 * scheduled sync and the webhook keep them current — vehicle.added and
 * driver.added both trigger a pass — and Terminal bills for what gets read, so
 * opening a dropdown must not cost a provider call. ?refresh=1 is the escape
 * hatch for a carrier who added a truck thirty seconds ago, and it is entities
 * only for that reason.
 */
class EldFleetController extends BaseController
{
    public function __construct(private EldSyncService $sync) {}

    /**
     * Carriers this broker has onboarded who have a live ELD connection.
     *
     * Scoped through the connect request, which is what ties a connection to
     * the broker that asked for it. A connection with no request from this
     * company is another broker's carrier and must not appear here, however
     * connected it is.
     */
    public function carriers()
    {
        $companyId = auth()->user()->company_id;

        $connections = EldConnection::query()
            ->where('status', EldConnection::STATUS_CONNECTED)
            ->whereHas('connectRequests', fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('carrier_legal_name')
            ->get();

        return $this->success(
            $connections->map(fn (EldConnection $connection) => [
                'connection_uuid' => $connection->uuid,
                'carrier_name' => $connection->carrier_legal_name,
                'dot_number' => $connection->carrier_dot_number,
                'provider' => $connection->provider,
                'vehicle_count' => (int) $connection->vehicle_count,
                'driver_count' => (int) $connection->driver_count,
                'sync_status' => $connection->sync_status,
                'last_sync_at' => optional($connection->last_sync_at)->toIso8601String(),

                // Surfaced rather than swallowed: a connection whose account
                // cannot read drivers will show an empty dropdown, and the
                // broker deserves to know why.
                'last_sync_error' => $connection->last_sync_error,
            ])->values(),
            'Connected ELD carriers retrieved successfully.'
        );
    }

    /**
     * The active vehicles and drivers on one connection.
     */
    public function fleet(Request $request, string $connectionUuid)
    {
        $connection = $this->connectionForCompany($connectionUuid);

        if ($request->boolean('refresh') && $connection->isSyncable()) {
            /*
            | Inline, and entities only. The broker is watching a spinner, not
            | a queue — and the full sync pass would walk position history one
            | truck at a time, which is the wrong cost and the wrong latency
            | for "my carrier just added a trailer".
            */
            $this->sync->syncFleetOnly($connection);
            $connection->refresh();
        }

        $vehicles = $connection->vehicles()
            ->tap(fn ($q) => $this->onlyActive($q))

            // Unit number, else plate, else VIN — whichever the provider gave
            // us is what the dropdown label falls back to, so the sort has to
            // follow the same order or the list reads as unsorted.
            ->orderByRaw('COALESCE(NULLIF(name, ?), license_plate, vin)', [''])
            ->get()
            ->map(fn ($vehicle) => [
                'id' => $vehicle->id,
                'terminal_id' => $vehicle->terminal_id,
                'name' => $vehicle->name,
                'vin' => $vehicle->vin,
                'license_plate' => $vehicle->license_plate,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'status' => $vehicle->status,

                // What the dropdown shows when the provider left `name` empty,
                // which several do. Resolved here rather than in the frontend
                // so every screen labels a truck the same way.
                'label' => $vehicle->name
                    ?: $vehicle->license_plate
                    ?: $vehicle->vin
                    ?: $vehicle->terminal_id,
            ])
            ->values();

        $drivers = $connection->drivers()
            ->tap(fn ($q) => $this->onlyActive($q))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($driver) => [
                'id' => $driver->id,
                'terminal_id' => $driver->terminal_id,
                'name' => trim($driver->first_name.' '.$driver->last_name) ?: $driver->username,
                'phone' => $driver->phone,
                'license_number' => $driver->license_number,
                'license_state' => $driver->license_state,
                'status' => $driver->status,
            ])
            ->values();

        return $this->success([
            'connection_uuid' => $connection->uuid,
            'carrier_name' => $connection->carrier_legal_name,
            'dot_number' => $connection->carrier_dot_number,
            'last_sync_at' => optional($connection->last_sync_at)->toIso8601String(),
            'last_sync_error' => $connection->last_sync_error,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
        ], 'ELD fleet retrieved successfully.');
    }

    /**
     * Active, as the UI means it.
     *
     * Terminal passes the provider's own status through untouched, and not
     * every provider reports one. A null status is treated as active rather
     * than hidden — a carrier whose provider is silent on the matter would
     * otherwise get an empty dropdown and no way to book a load at all.
     */
    private function onlyActive($query): void
    {
        $query->where(function ($inner) {
            $inner->whereNull('status')
                ->orWhereRaw('LOWER(status) = ?', ['active']);
        });
    }

    private function connectionForCompany(string $uuid): EldConnection
    {
        $companyId = auth()->user()->company_id;

        return EldConnection::query()
            ->where('uuid', $uuid)
            ->where('status', EldConnection::STATUS_CONNECTED)
            ->whereHas('connectRequests', fn ($q) => $q->where('company_id', $companyId))
            ->firstOrFail();
    }
}