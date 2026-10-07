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
                // broker deserves to know why. Filtered through
                // visibleSyncError() - see its docblock for what's excluded
                // and why.
                'last_sync_error' => $this->visibleSyncError($connection),
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
            'last_sync_error' => $this->visibleSyncError($connection),
            'vehicles' => $vehicles,
            'drivers' => $drivers,
        ], 'ELD fleet retrieved successfully.');
    }

    /**
     * Resources whose "not available to this Terminal account" note is a
     * known, accepted gap rather than something a broker needs to see.
     *
     * hos: not attempted any more (see EldSyncService::sync()), but a
     * connection can still be carrying an older stored message naming it
     * from before that change - filtered here too so a stale row doesn't
     * show it until its next sync happens to overwrite it clean.
     *
     * locations: still attempted every cycle, on purpose - see
     * visibleSyncError()'s docblock.
     */
    private const ACCEPTED_SYNC_GAPS = ['hos', 'locations'];

    /**
     * last_sync_error, with the known, accepted gaps filtered out.
     *
     * Reading vehicle positions needs Terminal's vehicle-location:read
     * scope, which this account does not have. That is accepted, not a
     * bug - EldSyncService and EldTrackingService both keep quietly
     * retrying it every cycle regardless, so live tracking starts working
     * the moment Terminal grants the scope, with no code change needed.
     * Broadcasting that retry to every broker on every page load is not
     * accepted, so it never reaches this response.
     *
     * Nothing else is filtered: a connection whose account genuinely can't
     * read vehicles or drivers still needs to tell the broker why their
     * dropdown is empty, so any other reason still comes through.
     *
     * Two message shapes reach here, both written elsewhere:
     *  - EldSyncService's combined "Not available to this Terminal
     *    account: a (...); b (...)" - only the accepted-gap clauses are
     *    stripped, the rest of the sentence survives.
     *  - EldTrackingService's live-poll failure, which is locations-only
     *    by construction (its one catch site) - hidden outright.
     */
    private function visibleSyncError(EldConnection $connection): ?string
    {
        $error = $connection->last_sync_error;

        if ($error === null) {
            return null;
        }

        if (str_starts_with($error, 'Live location is not available')) {
            return null;
        }

        $prefix = 'Not available to this Terminal account: ';

        if (str_starts_with($error, $prefix)) {
            $remaining = array_values(array_filter(
                explode('; ', substr($error, strlen($prefix))),
                fn ($clause) => ! collect(self::ACCEPTED_SYNC_GAPS)
                    ->contains(fn ($resource) => str_starts_with($clause, $resource.' '))
            ));

            return $remaining === [] ? null : $prefix.implode('; ', $remaining);
        }

        return $error;
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