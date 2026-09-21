<?php

namespace App\Services\Eld;

use App\Models\EldConnection;
use App\Models\EldDriver;
use App\Models\EldHosLog;
use App\Models\EldVehicle;
use App\Models\EldVehicleLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls a connection's fleet into our own tables.
 *
 * Nothing on a request path calls this — the broker dashboard reads the stored
 * rows. A first sync backfills a month of duty-status logs for an entire fleet
 * and is measured in minutes, not milliseconds.
 *
 * On field names: Terminal's published model docs and their OpenAPI spec do not
 * agree on every key (`status` vs `dutyStatus`, a nested `driver` object vs a
 * flat `driverId`), and per-provider coverage varies on top of that. So every
 * extraction below accepts either spelling and the untouched object is stored
 * in `payload` regardless. A field we guessed wrong is then a column to
 * backfill, not data we threw away.
 */
class EldSyncService
{
    public function __construct(
        protected TerminalClient $terminal
    ) {}

    /**
     * Full pass over one connection.
     *
     * Each collection is attempted on its own and a failure in one does not
     * abandon the rest. Terminal scopes permissions per resource, so an
     * account without `hos:read` answers 403 there and 200 everywhere else —
     * losing the whole fleet over one missing scope would be absurd, and
     * retrying it would not help either.
     *
     * The distinction that matters downstream:
     *   completed  everything came across
     *   partial    some resources refused; what arrived is real
     *   failed     nothing came across, and the job should retry
     */
    public function sync(EldConnection $connection): array
    {
        $connection->forceFill([
            'sync_status' => 'running',
            'last_sync_error' => null,
        ])->save();

        // The liveness check, and the only fatal step: if the connection
        // itself cannot be read, the four below are certain to fail too.
        try {
            $this->refreshConnection($connection);
        } catch (Throwable $e) {
            $this->recordFailure($connection, $e);

            throw $e;
        }

        $counts = [];
        $errors = [];

        foreach ([
            'vehicles' => fn () => $this->syncVehicles($connection),
            'drivers' => fn () => $this->syncDrivers($connection),
            'hos_logs' => fn () => $this->syncHosLogs($connection),
            'locations' => fn () => $this->syncVehicleLocations($connection),
        ] as $resource => $pull) {
            try {
                $counts[$resource] = $pull();
            } catch (Throwable $e) {
                $errors[$resource] = $e->getMessage();

                Log::warning('ELD sync could not read '.$resource, array_merge([
                    'connection' => $connection->terminal_connection_id,
                    'error' => $e->getMessage(),
                ], $e instanceof TerminalRequestException ? $e->context() : []));
            }
        }

        // Nothing at all came across — that is a failure, and the job retrying
        // it is the right response.
        if (count($errors) === 4) {
            $e = new TerminalRequestException(
                'Terminal refused every resource on this connection.',
                0,
                json_encode($errors)
            );

            $this->recordFailure($connection, $e);

            throw $e;
        }

        $connection->forceFill([
            'sync_status' => $errors ? 'partial' : 'completed',
            'last_synced_at' => now(),
            'last_sync_error' => $errors
                ? mb_substr(json_encode($errors), 0, 1000)
                : null,
        ])->save();

        return $counts;
    }

    protected function recordFailure(EldConnection $connection, Throwable $e): void
    {
        $connection->forceFill([
            'sync_status' => 'failed',
            'last_sync_error' => mb_substr($e->getMessage(), 0, 1000),
        ])->save();

        Log::error('ELD sync failed', array_merge([
            'connection' => $connection->terminal_connection_id,
            'error' => $e->getMessage(),
        ], $e instanceof TerminalRequestException ? $e->context() : []));
    }

    /**
     * Refresh the connection's own metadata — provider, status, last sync.
     *
     * Doubles as the liveness check: a revoked connection answers here before
     * four collection endpoints each fail in turn.
     */
    public function refreshConnection(EldConnection $connection): void
    {
        $payload = $this->terminal->currentConnection($connection->connection_token);

        $status = $payload['status'] ?? EldConnection::STATUS_CONNECTED;

        $connection->forceFill(array_filter([
            'provider_code' => Arr::get($payload, 'provider.code'),
            'provider_name' => Arr::get($payload, 'provider.name'),
            'status' => $status,
            'account_name' => Arr::get($payload, 'account.name') ?? Arr::get($payload, 'company.name'),
            'dot_numbers' => Arr::get($payload, 'account.dotNumbers') ?? Arr::get($payload, 'company.dotNumbers'),
            'external_id' => $payload['externalId'] ?? null,
            'payload' => $payload,
            'disconnected_at' => $status === EldConnection::STATUS_CONNECTED
                ? null
                : ($connection->disconnected_at ?? now()),
        ], fn ($value) => $value !== null))->save();
    }

    public function syncVehicles(EldConnection $connection): int
    {
        $rows = $this->terminal->paginate('/vehicles', $connection->connection_token);

        foreach ($rows as $row) {
            EldVehicle::updateOrCreate(
                [
                    'eld_connection_id' => $connection->id,
                    'terminal_id' => $row['id'],
                ],
                [
                    'source_id' => $row['sourceId'] ?? null,
                    'provider' => $row['provider'] ?? $connection->provider_code,
                    'status' => $row['status'] ?? null,
                    'vin' => $row['vin'] ?? null,
                    'name' => $row['name'] ?? null,
                    'make' => $row['make'] ?? null,
                    'model' => $row['model'] ?? null,
                    'year' => $row['year'] ?? null,
                    'license_plate_state' => Arr::get($row, 'licensePlate.state'),
                    'license_plate_number' => Arr::get($row, 'licensePlate.number'),
                    'payload' => $row,
                ]
            );
        }

        return count($rows);
    }

    public function syncDrivers(EldConnection $connection): int
    {
        $rows = $this->terminal->paginate('/drivers', $connection->connection_token);

        foreach ($rows as $row) {
            [$first, $last] = $this->splitName($row);

            EldDriver::updateOrCreate(
                [
                    'eld_connection_id' => $connection->id,
                    'terminal_id' => $row['id'],
                ],
                [
                    'source_id' => $row['sourceId'] ?? $row['externalId'] ?? null,
                    'provider' => $row['provider'] ?? $connection->provider_code,
                    'status' => $row['status'] ?? null,
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'license_number' => $row['licenseNumber'] ?? Arr::get($row, 'license.number'),
                    'license_state' => $row['licenseState'] ?? Arr::get($row, 'license.state'),
                    'payload' => $row,
                ]
            );
        }

        return count($rows);
    }

    /**
     * Duty-status logs, windowed rather than "everything".
     *
     * A first sync takes the configured backfill; later ones only need what has
     * changed since the last successful pass, with a day of overlap because a
     * log can be edited after the fact and a driver can cross a boundary
     * mid-status.
     */
    public function syncHosLogs(EldConnection $connection): int
    {
        $since = $connection->last_synced_at
            ? CarbonImmutable::parse($connection->last_synced_at)->subDay()
            : CarbonImmutable::now()->subDays((int) config('terminal.backfill_days', 30));

        $rows = $this->terminal->paginate('/hos/logs', $connection->connection_token, [
            'startedAfter' => $since->toIso8601String(),
        ]);

        foreach ($rows as $row) {
            EldHosLog::updateOrCreate(
                [
                    'eld_connection_id' => $connection->id,
                    'terminal_id' => $row['id'],
                ],
                [
                    'source_id' => $row['sourceId'] ?? null,
                    'provider' => $row['provider'] ?? $connection->provider_code,
                    'status' => $row['status'] ?? $row['dutyStatus'] ?? null,
                    'driver_terminal_id' => $this->relationId($row, 'driver'),
                    'vehicle_terminal_id' => $this->relationId($row, 'vehicle'),
                    'started_at' => $row['startedAt'] ?? null,
                    'ended_at' => $row['endedAt'] ?? null,
                    'latitude' => Arr::get($row, 'location.latitude'),
                    'longitude' => Arr::get($row, 'location.longitude'),
                    'remarks' => is_array($row['remarks'] ?? null)
                        ? implode(' | ', $row['remarks'])
                        : ($row['remarks'] ?? null),
                    'payload' => $row,
                ]
            );
        }

        return count($rows);
    }

    /**
     * One pin per truck. Overwritten each pass — the trail stays at Terminal.
     */
    public function syncVehicleLocations(EldConnection $connection): int
    {
        $rows = $this->terminal->paginate('/vehicles/locations', $connection->connection_token);

        foreach ($rows as $row) {
            $vehicleId = $this->relationId($row, 'vehicle');

            if (! $vehicleId) {
                continue;
            }

            EldVehicleLocation::updateOrCreate(
                [
                    'eld_connection_id' => $connection->id,
                    'vehicle_terminal_id' => $vehicleId,
                ],
                [
                    'latitude' => $row['latitude'] ?? Arr::get($row, 'location.latitude'),
                    'longitude' => $row['longitude'] ?? Arr::get($row, 'location.longitude'),
                    'speed' => $row['speed'] ?? null,
                    'heading' => $row['heading'] ?? null,
                    'description' => Arr::get($row, 'address.formatted')
                        ?? $row['description']
                        ?? Arr::get($row, 'location.description'),
                    'located_at' => $row['locatedAt'] ?? $row['timestamp'] ?? null,
                    'payload' => $row,
                ]
            );
        }

        return count($rows);
    }

    /**
     * Resolve a reference to another record.
     *
     * Terminal points at a related entity in three different ways depending on
     * the endpoint: a bare id string (`"vehicle": "vcl_..."` — what
     * /vehicles/locations actually returns), an expanded object
     * (`"vehicle": {"id": ...}` when `expand` is used), or a flat sibling key
     * (`vehicleId`). Reading only one of them silently drops every row, which
     * is exactly what happened to locations until a real sandbox response was
     * put in front of it.
     */
    protected function relationId(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && ! empty($value['id'])) {
            return $value['id'];
        }

        $flat = $row[$key.'Id'] ?? null;

        return is_string($flat) && $flat !== '' ? $flat : null;
    }

    /**
     * Terminal sends drivers as a single `name` on some providers and split
     * first/last on others.
     */
    protected function splitName(array $row): array
    {
        if (! empty($row['firstName']) || ! empty($row['lastName'])) {
            return [$row['firstName'] ?? null, $row['lastName'] ?? null];
        }

        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
