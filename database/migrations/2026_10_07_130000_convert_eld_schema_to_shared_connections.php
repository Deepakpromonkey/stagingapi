<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Brings production's ELD schema in line with staging's.
 *
 * Production's first ELD integration (2026_08_31_*) kept one connection per
 * onboarding. Staging's keeps one connection per carrier, shared across every
 * broker they onboard with, with each broker's consent recorded as a grant —
 * so a carrier links their provider once and Terminal bills one connection
 * rather than one per broker relationship.
 *
 * The tables share their names, so staging's create migrations cannot simply
 * run here. This converts in place instead:
 *
 *   - eld_connections is rebuilt in staging's shape. Every row is kept with its
 *     id, so carrier_connect_requests.eld_connection_id still points at it. The
 *     carrier fields come from the onboarding that made it, and the connection
 *     token — stored in plain text before — is encrypted, as staging's model
 *     expects.
 *   - Each onboarding already linked to a connection becomes a grant for that
 *     broker, so no broker loses access.
 *   - The fleet tables are dropped and recreated empty. Their shapes differ too
 *     much to map, and they are a cache of Terminal's data: run
 *     `php artisan eld:sync-active --all` after deploying to refill them.
 *
 * One way only; down() refuses rather than half-restoring the old shape.
 */
return new class extends Migration
{
    private const LEGACY_FLEET_TABLES = [
        'eld_vehicle_locations',
        'eld_hos_logs',
        'eld_drivers',
        'eld_vehicles',
    ];

    public function up(): void
    {
        // Already converted, or never had the old shape.
        if (! Schema::hasTable('eld_connections') || ! Schema::hasColumn('eld_connections', 'provider_code')) {
            return;
        }

        $connections = DB::table('eld_connections')->get();

        $linkedRequests = DB::table('carrier_connect_requests')
            ->whereNotNull('eld_connection_id')
            ->orderBy('id')
            ->get(['id', 'company_id', 'carrier_dot_number', 'carrier_row_id', 'carrier_legal_name', 'eld_connection_id', 'eld_connected_at']);

        $vehicleCounts = $this->countsBy('eld_vehicles');
        $driverCounts = $this->countsBy('eld_drivers');

        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->dropForeign(['eld_connection_id']);
        });

        Schema::disableForeignKeyConstraints();

        foreach (self::LEGACY_FLEET_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::drop('eld_connections');

        Schema::enableForeignKeyConstraints();

        $this->createConnectionsTable();

        foreach ($connections as $legacy) {
            $request = $linkedRequests->firstWhere('eld_connection_id', $legacy->id);

            DB::table('eld_connections')->insert([
                'id' => $legacy->id,
                'uuid' => $legacy->uuid ?: (string) Str::uuid(),
                'carrier_dot_number' => $request->carrier_dot_number ?? $this->dotFromLegacy($legacy) ?? '',
                'carrier_row_id' => $request->carrier_row_id ?? null,
                'carrier_legal_name' => $request->carrier_legal_name ?? $legacy->account_name,
                'terminal_connection_id' => $legacy->terminal_connection_id,
                'external_id' => $legacy->external_id,
                'provider' => $legacy->provider_name ?: $legacy->provider_code,
                'connection_token' => $this->encrypted($legacy->connection_token),
                'status' => $legacy->status === 'deleted' ? 'archived' : ($legacy->status ?: 'connected'),
                'sync_status' => match ($legacy->sync_status) {
                    'completed', 'partial' => 'completed',
                    'failed' => 'failed',
                    default => 'pending',
                },
                'last_sync_at' => $legacy->last_synced_at,
                'last_sync_error' => $legacy->last_sync_error,
                'vehicle_count' => $vehicleCounts[$legacy->id] ?? 0,
                'driver_count' => $driverCounts[$legacy->id] ?? 0,
                'connected_at' => $legacy->connected_at,
                'disconnected_at' => $legacy->disconnected_at,
                'archived_at' => $legacy->status === 'deleted' ? ($legacy->updated_at ?? now()) : null,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ]);
        }

        Schema::table('carrier_connect_requests', function (Blueprint $table) {
            $table->foreign('eld_connection_id')
                ->references('id')->on('eld_connections')
                ->nullOnDelete();

            $table->timestamp('eld_link_state_at')->nullable()->after('eld_link_state');
        });

        $this->createGrantsTable();
        $this->createFleetTables();
        $this->createWebhookEventsTable();

        Schema::table('companies', function (Blueprint $table) {
            $table->string('eld_consent_template', 64)->nullable();
        });

        // One grant per broker per connection — the first onboarding that
        // linked it is the one recorded.
        $linkedRequests
            ->unique(fn ($request) => $request->eld_connection_id.'-'.$request->company_id)
            ->each(function ($request) {
                DB::table('eld_connection_grants')->insert([
                    'eld_connection_id' => $request->eld_connection_id,
                    'company_id' => $request->company_id,
                    'carrier_connect_request_id' => $request->id,
                    'granted_at' => $request->eld_connected_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The ELD schema conversion is one way: the old per-onboarding shape cannot hold shared connections.'
        );
    }

    /** @return array<int, int> */
    private function countsBy(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->select('eld_connection_id', DB::raw('COUNT(*) as total'))
            ->groupBy('eld_connection_id')
            ->pluck('total', 'eld_connection_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * A DOT for a connection no onboarding points at any more: Terminal's
     * external id when it was sent as `dot:1234567`, else the first DOT the
     * provider reported.
     */
    private function dotFromLegacy(object $legacy): ?string
    {
        if (is_string($legacy->external_id) && str_starts_with($legacy->external_id, 'dot:')) {
            return substr($legacy->external_id, 4);
        }

        $dots = json_decode((string) $legacy->dot_numbers, true);

        return is_array($dots) && $dots ? (string) reset($dots) : null;
    }

    // Plain text before; encrypted is what the new model's cast reads.
    private function encrypted(?string $token): string
    {
        $token = (string) $token;

        try {
            Crypt::decryptString($token);

            return $token;
        } catch (Throwable) {
            return Crypt::encryptString($token);
        }
    }

    /*
    | The schema below is staging's, as created by its 2026_09_08_* migrations.
    | Kept identical so the two databases stay interchangeable.
    */

    private function createConnectionsTable(): void
    {
        Schema::create('eld_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('carrier_dot_number', 20)->index();
            $table->string('carrier_row_id', 40)->nullable()->index();
            $table->string('carrier_legal_name')->nullable();

            $table->string('terminal_connection_id', 64)->unique();
            $table->string('external_id', 64)->nullable()->index();
            $table->string('provider', 40)->nullable();

            $table->text('connection_token');

            $table->string('status', 20)->default('connected')->index();

            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->unsignedInteger('vehicle_count')->default(0);
            $table->unsignedInteger('driver_count')->default(0);

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
        });
    }

    private function createGrantsTable(): void
    {
        Schema::create('eld_connection_grants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('eld_connection_id')
                ->constrained('eld_connections')
                ->cascadeOnDelete();

            $table->foreignId('company_id')->constrained('companies');

            $table->foreignId('carrier_connect_request_id')
                ->nullable()
                ->constrained('carrier_connect_requests')
                ->nullOnDelete();

            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            $table->string('consent_template', 64)->nullable();

            $table->timestamps();

            $table->unique(
                ['eld_connection_id', 'company_id'],
                'eld_connection_grants_connection_company_unique'
            );
        });
    }

    private function createFleetTables(): void
    {
        Schema::create('eld_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id');
            $table->foreign('eld_connection_id', 'eld_vehicles_connection_fk')
                ->references('id')->on('eld_connections')
                ->cascadeOnDelete();

            $table->string('terminal_id', 64);
            $table->string('name')->nullable();
            $table->string('vin', 20)->nullable()->index();
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('year', 4)->nullable();
            $table->string('license_plate', 20)->nullable();
            $table->string('status', 30)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('terminal_modified_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id'], 'eld_vehicles_connection_terminal_unique');
        });

        Schema::create('eld_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id');
            $table->foreign('eld_connection_id', 'eld_drivers_connection_fk')
                ->references('id')->on('eld_connections')
                ->cascadeOnDelete();

            $table->string('terminal_id', 64);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('license_number', 40)->nullable();
            $table->string('license_state', 5)->nullable();
            $table->string('status', 30)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('terminal_modified_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id'], 'eld_drivers_connection_terminal_unique');
        });

        Schema::create('eld_hos_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id');
            $table->foreign('eld_connection_id', 'eld_hos_logs_connection_fk')
                ->references('id')->on('eld_connections')
                ->cascadeOnDelete();

            $table->string('terminal_id', 64);
            $table->string('driver_terminal_id', 64)->nullable()->index();
            $table->string('vehicle_terminal_id', 64)->nullable()->index();
            $table->string('duty_status', 30)->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('terminal_modified_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['eld_connection_id', 'terminal_id'], 'eld_hos_logs_connection_terminal_unique');
        });

        Schema::create('eld_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id');
            $table->foreign('eld_connection_id', 'eld_locations_connection_fk')
                ->references('id')->on('eld_connections')
                ->cascadeOnDelete();

            $table->string('vehicle_terminal_id', 64);
            $table->timestamp('located_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_mph', 6, 2)->nullable();
            $table->decimal('heading_degrees', 6, 2)->nullable();
            $table->unsignedBigInteger('odometer_miles')->nullable();
            $table->string('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['eld_connection_id', 'vehicle_terminal_id', 'located_at'],
                'eld_locations_connection_vehicle_time_unique'
            );

            $table->index(['eld_connection_id', 'located_at'], 'eld_locations_connection_time_index');
        });

        Schema::create('eld_sync_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eld_connection_id');
            $table->foreign('eld_connection_id', 'eld_sync_checkpoints_connection_fk')
                ->references('id')->on('eld_connections')
                ->cascadeOnDelete();

            $table->string('resource', 20);
            $table->timestamp('synced_through')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_record_count')->default(0);
            $table->timestamps();

            $table->unique(['eld_connection_id', 'resource'], 'eld_sync_checkpoints_connection_resource_unique');
        });
    }

    private function createWebhookEventsTable(): void
    {
        Schema::create('eld_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('type', 60)->index();
            $table->string('terminal_connection_id', 64)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }
};
