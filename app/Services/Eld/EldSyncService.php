<?php

namespace App\Services\Eld;

use App\Models\Eld\EldConnection;
use App\Models\Eld\EldDriver;
use App\Models\Eld\EldHosLog;
use App\Models\Eld\EldLocation;
use App\Models\Eld\EldSyncCheckpoint;
use App\Models\Eld\EldVehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls a connection's fleet out of Terminal and into our tables.
 *
 * Two things shape everything here.
 *
 * Terminal meters synced data, not entities — vehicles and drivers cost nothing
 * to hold, and the bill is what you read for them. So this syncs incrementally
 * from a checkpoint rather than re-reading the world, and the scheduler only
 * calls it for carriers a broker is actually hauling with.
 *
 * And the two clocks are not the same. Entities and HOS are queried by
 * INGESTION time (`modifiedAfter` — when Terminal processed it), which is
 * monotonic and needs no lookback. Locations can only be queried by RECORD time
 * (`startAt` — when the truck was there), where a ping that reaches the
 * provider late would fall in the gap between two runs, so that one re-reads an
 * overlapping window and relies on the unique key to collapse the duplicates.
 */
class EldSyncService
{
    public function __construct(private TerminalClient $terminal) {}

    /**
     * @param  bool  $initial  the first pass after a carrier connects
     */
    public function sync(EldConnection $connection, bool $initial = false): void
    {
        if (! $connection->isSyncable()) {
            Log::info('Skipping ELD sync for a connection that is not connected', [
                'connection' => $connection->uuid,
                'status' => $connection->status,
            ]);

            return;
        }

        $connection->forceFill(['sync_status' => EldConnection::SYNC_RUNNING])->save();

        try {
            $unavailable = array_filter([
                $this->attempt('vehicles', fn () => $this->syncVehicles($connection, $initial)),
                $this->attempt('drivers', fn () => $this->syncDrivers($connection, $initial)),
                $this->attempt('hos', fn () => $this->syncHosLogs($connection, $initial)),
                $this->attempt('locations', fn () => $this->syncLocations($connection, $initial)),
            ]);

            $connection->forceFill([
                'sync_status' => EldConnection::SYNC_COMPLETED,
                'last_sync_at' => now(),

                /*
                | A completed sync that could not read everything still says so.
                | Written here rather than left null because a broker looking at
                | an empty hours-of-service column needs to know the account was
                | refused, not that the driver never drove.
                */
                'last_sync_error' => $unavailable === []
                    ? null
                    : 'Not available to this Terminal account: '.implode('; ', $unavailable),

                'vehicle_count' => $connection->vehicles()->count(),
                'driver_count' => $connection->drivers()->count(),
            ])->save();
        } catch (TerminalRequestException $e) {
            /*
            | Deliberately leaves every checkpoint where it was. A failed pass
            | that advanced its checkpoint would skip the window it never read,
            | and nothing would ever come back for it.
            */
            $connection->forceFill([
                'sync_status' => EldConnection::SYNC_FAILED,
                'last_sync_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    /**
     * Run one resource's sync, tolerating an account that cannot see it.
     *
     * Terminal meters and entitles each model separately, and providers differ
     * in what they expose at all — so a connection that yields vehicles and
     * drivers may be refused hours of service outright. Failing the whole pass
     * on that would throw away the resources that did work and would keep
     * failing, since a missing permission answers the same way every time.
     *
     * Only entitlement refusals are absorbed. A timeout or a 5xx still raises,
     * so the pass fails, the checkpoints stay put, and the next run retries the
     * same window.
     *
     * @param  callable():void  $sync
     * @return string|null a note naming what could not be read, or null
     */
    private function attempt(string $resource, callable $sync): ?string
    {
        try {
            $sync();

            return null;
        } catch (TerminalPermissionException $e) {
            Log::warning('Skipping ELD resource this Terminal account cannot read', [
                'resource' => $resource,
                'detail' => $e->getMessage(),
            ]);

            return $resource.' ('.$e->getMessage().')';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Entities
    // ─────────────────────────────────────────────────────────────────────────

    private function syncVehicles(EldConnection $connection, bool $initial): void
    {
        $newest = null;

        $count = $this->terminal->paginate(
            $connection->connection_token,
            '/vehicles',
            $this->ingestionWindow($connection, 'vehicles', $initial),
            function (array $rows) use ($connection, &$newest) {
                foreach ($rows as $row) {
                    $modifiedAt = $this->timestamp(data_get($row, 'metadata.modifiedAt'));

                    EldVehicle::updateOrCreate(
                        [
                            'eld_connection_id' => $connection->id,
                            'terminal_id' => $row['id'],
                        ],
                        [
                            'name' => data_get($row, 'name'),
                            'vin' => data_get($row, 'vin'),
                            'make' => data_get($row, 'make'),
                            'model' => data_get($row, 'model'),
                            'year' => data_get($row, 'year'),

                            // licensePlate is an object of state + number, not
                            // a string — the number alone is what a broker
                            // reads on a bill of lading.
                            'license_plate' => data_get($row, 'licensePlate.number'),

                            'status' => data_get($row, 'status'),
                            'payload' => $row,
                            'terminal_modified_at' => $modifiedAt,
                        ]
                    );

                    $newest = $this->latest($newest, $modifiedAt);
                }
            }
        );

        $this->advance($connection, 'vehicles', $newest, $count);
    }

    private function syncDrivers(EldConnection $connection, bool $initial): void
    {
        $newest = null;

        $count = $this->terminal->paginate(
            $connection->connection_token,
            '/drivers',
            $this->ingestionWindow($connection, 'drivers', $initial),
            function (array $rows) use ($connection, &$newest) {
                foreach ($rows as $row) {
                    $modifiedAt = $this->timestamp(data_get($row, 'metadata.modifiedAt'));

                    EldDriver::updateOrCreate(
                        [
                            'eld_connection_id' => $connection->id,
                            'terminal_id' => $row['id'],
                        ],
                        [
                            'first_name' => data_get($row, 'firstName'),
                            'last_name' => data_get($row, 'lastName'),
                            'username' => data_get($row, 'username'),
                            'phone' => data_get($row, 'phone'),
                            'license_number' => data_get($row, 'licenseNumber'),
                            'license_state' => data_get($row, 'licenseState'),
                            'status' => data_get($row, 'status'),
                            'payload' => $row,
                            'terminal_modified_at' => $modifiedAt,
                        ]
                    );

                    $newest = $this->latest($newest, $modifiedAt);
                }
            }
        );

        $this->advance($connection, 'drivers', $newest, $count);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Records
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Duty status changes.
     *
     * Read by ingestion time, which matters more here than anywhere else: an
     * HOS record can reach the provider hours after the driver made the change,
     * and a record-time query would have already moved past it.
     *
     * The field names below are read through fallbacks on purpose. Terminal's
     * published model page lists provider support but not the object's shape,
     * so the two plausible spellings are both accepted and the untouched row is
     * kept in `payload` either way. Confirm against a sandbox response and the
     * fallbacks can come out.
     */
    private function syncHosLogs(EldConnection $connection, bool $initial): void
    {
        $newest = null;

        $count = $this->terminal->paginate(
            $connection->connection_token,
            '/hos/logs',
            $this->ingestionWindow($connection, 'hos', $initial),
            function (array $rows) use ($connection, &$newest) {
                foreach ($rows as $row) {
                    $modifiedAt = $this->timestamp(data_get($row, 'metadata.modifiedAt'));

                    $startedAt = $this->timestamp(
                        data_get($row, 'startTime') ?? data_get($row, 'startedAt')
                    );

                    $endedAt = $this->timestamp(
                        data_get($row, 'endTime') ?? data_get($row, 'endedAt')
                    );

                    EldHosLog::updateOrCreate(
                        [
                            'eld_connection_id' => $connection->id,
                            'terminal_id' => $row['id'],
                        ],
                        [
                            'driver_terminal_id' => data_get($row, 'driver'),
                            'vehicle_terminal_id' => data_get($row, 'vehicle'),

                            'duty_status' => data_get($row, 'type')
                                ?? data_get($row, 'dutyStatus')
                                ?? data_get($row, 'status'),

                            'started_at' => $startedAt,
                            'ended_at' => $endedAt,

                            /*
                            | Derived only when the provider does not report
                            | it. Ordered start-to-end and taken as an absolute
                            | value: Carbon returns a SIGNED difference, and the
                            | column is unsigned — a negative here is rejected
                            | outright on MySQL.
                            */
                            'duration_seconds' => data_get($row, 'duration')
                                ?? ($startedAt && $endedAt
                                    ? (int) abs($startedAt->diffInSeconds($endedAt))
                                    : null),

                            'payload' => $row,
                            'terminal_modified_at' => $modifiedAt,
                        ]
                    );

                    $newest = $this->latest($newest, $modifiedAt);
                }
            }
        );

        $this->advance($connection, 'hos', $newest, $count);
    }

    /**
     * Vehicle positions, per vehicle.
     *
     * This is the expensive one — it is a call per truck per pass, and it is
     * the reason the scheduler limits ongoing syncing to carriers with a live
     * load rather than everyone who ever onboarded.
     */
    private function syncLocations(EldConnection $connection, bool $initial): void
    {
        $checkpoint = $this->checkpoint($connection, 'locations');

        $startAt = $checkpoint->synced_through
            ? $checkpoint->synced_through->copy()->subHours((int) config('services.terminal.lookback_hours', 48))
            : $this->backfillStart($initial);

        if ($startAt === null) {
            // No backfill configured and nothing read yet: start the clock now
            // rather than pulling history nobody asked for and paying for it.
            $startAt = now();
        }

        $newest = null;
        $total = 0;

        $vehicles = $connection->vehicles()->pluck('terminal_id');

        foreach ($vehicles as $vehicleId) {
            $total += $this->terminal->paginate(
                $connection->connection_token,
                '/vehicles/'.$vehicleId.'/locations',
                ['startAt' => $startAt->toIso8601ZuluString('millisecond')],
                function (array $rows) use ($connection, $vehicleId, &$newest) {
                    foreach ($rows as $row) {
                        $locatedAt = $this->timestamp(data_get($row, 'locatedAt'));

                        if ($locatedAt === null) {
                            continue;
                        }

                        EldLocation::updateOrCreate(
                            [
                                'eld_connection_id' => $connection->id,

                                // The row's own vehicle reference where there
                                // is one, else the vehicle whose endpoint this
                                // came from — they agree, but the payload is
                                // the more authoritative of the two.
                                'vehicle_terminal_id' => data_get($row, 'vehicle') ?: $vehicleId,
                                'located_at' => $locatedAt,
                            ],
                            [
                                'latitude' => data_get($row, 'location.latitude'),
                                'longitude' => data_get($row, 'location.longitude'),
                                'speed_mph' => data_get($row, 'speed'),
                                'heading_degrees' => data_get($row, 'heading'),
                                'description' => data_get($row, 'address.formatted'),
                                'payload' => $row,
                            ]
                        );

                        $newest = $this->latest($newest, $locatedAt);
                    }
                }
            );
        }

        /*
        | Record time, so the checkpoint is the newest position actually seen.
        | If a pass reads nothing the checkpoint stays put and the next run
        | asks for the same window again — which is correct: an empty window
        | is not evidence that nothing will ever land in it.
        */
        $this->advance($connection, 'locations', $newest, $total);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Checkpoints
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The query window for an ingestion-time resource.
     *
     * No lookback: `modifiedAfter` is Terminal's own processing clock, so
     * anything that arrives late arrives with a later ingestion stamp and the
     * next pass sees it regardless of when the event itself happened.
     */
    private function ingestionWindow(EldConnection $connection, string $resource, bool $initial): array
    {
        $checkpoint = $this->checkpoint($connection, $resource);

        $from = $checkpoint->synced_through ?: $this->backfillStart($initial);

        return $from
            ? ['modifiedAfter' => $from->toIso8601ZuluString('millisecond')]
            : [];
    }

    /**
     * Where a first sync starts reading from.
     *
     * Null means "everything Terminal has for this connection", which is what
     * an initial pass wants when no backfill depth is configured — the
     * connection itself was created with `backfill_days`, so Terminal only
     * holds what was asked for.
     */
    private function backfillStart(bool $initial): ?Carbon
    {
        $days = (int) config('services.terminal.backfill_days', 0);

        if (! $initial || $days <= 0) {
            return null;
        }

        return now()->subDays($days);
    }

    private function checkpoint(EldConnection $connection, string $resource): EldSyncCheckpoint
    {
        return EldSyncCheckpoint::firstOrCreate([
            'eld_connection_id' => $connection->id,
            'resource' => $resource,
        ]);
    }

    /**
     * Move a checkpoint to the newest record the pass actually saw.
     *
     * Terminal's clock, never ours. Stamping `now()` here would silently skip
     * anything the provider was still processing when the run started.
     */
    private function advance(EldConnection $connection, string $resource, ?Carbon $newest, int $count): void
    {
        $checkpoint = $this->checkpoint($connection, $resource);

        $checkpoint->forceFill([
            'synced_through' => $this->latest($checkpoint->synced_through, $newest),
            'last_run_at' => now(),
            'last_record_count' => $count,
        ])->save();
    }

    private function latest(?CarbonInterface $a, ?CarbonInterface $b): ?Carbon
    {
        if ($a === null && $b === null) {
            return null;
        }

        if ($a === null) {
            return Carbon::instance($b);
        }

        if ($b === null) {
            return Carbon::instance($a);
        }

        return Carbon::instance($b->greaterThan($a) ? $b : $a);
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
