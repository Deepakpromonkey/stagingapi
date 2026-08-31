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
     * Returns the counts rather than throwing on a partial failure: a fleet
     * that synced its vehicles but tripped over HOS is still more useful than
     * no fleet at all, and the error is recorded on the connection.
     */
    public function sync(EldConnection $connection): array
    {
        $connection->forceFill([
            'sync_status' => 'running',
            'last_sync_error' => null,
        ])->save();

        $counts = [];

        try {
            $this->refreshConnection($connection);

            $counts['vehicles'] = $this->syncVehicles($connection);
            $counts['drivers'] = $this->syncDrivers($connection);
            $counts['hos_logs'] = $this->syncHosLogs($connection);
            $counts['locations'] = $this->syncVehicleLocations($connection);

            $connection->forceFill([
                'sync_status' => 'completed',
                'last_synced_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $connection->forceFill([
                'sync_status' => 'failed',
                'last_sync_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            Log::error('ELD sync failed', array_merge([
                'connection' => $connection->terminal_connection_id,
                'error' => $e->getMessage(),
            ], $e instanceof TerminalRequestException ? $e->context() : []));

            throw $e;
        }

        return $counts;
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
                    'driver_terminal_id' => $row['driverId'] ?? Arr::get($row, 'driver.id'),
                    'vehicle_terminal_id' => $row['vehicleId'] ?? Arr::get($row, 'vehicle.id'),
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
            $vehicleId = $row['vehicleId'] ?? Arr::get($row, 'vehicle.id');

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
                    'description' => $row['description'] ?? Arr::get($row, 'location.description'),
                    'located_at' => $row['timestamp'] ?? $row['locatedAt'] ?? null,
                    'payload' => $row,
                ]
            );
        }

        return count($rows);
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
