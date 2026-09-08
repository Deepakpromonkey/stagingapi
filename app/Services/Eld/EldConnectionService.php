<?php

namespace App\Services\Eld;

use App\Jobs\SyncEldConnection;
use App\Models\CarrierConnectRequest;
use App\Models\Eld\EldConnection;
use App\Models\Eld\EldConnectionGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The rules around a carrier's ELD connection.
 *
 * Terminal is reached through TerminalClient; everything about who owns a
 * connection, who may see it, and what happens when it breaks lives here.
 *
 * The shape worth understanding before reading on: a connection belongs to the
 * CARRIER and is shared across every broker they work with, while consent is
 * per broker and recorded as a grant. That is what stops the same fleet being
 * imported — and metered — once per broker relationship.
 */
class EldConnectionService
{
    /**
     * How long a Link state nonce is good for. Long enough for a carrier to
     * find their provider password, short enough that a return URL left in a
     * browser history is worthless.
     */
    private const STATE_TTL_MINUTES = 60;

    public function __construct(private TerminalClient $terminal) {}

    public function isConfigured(): bool
    {
        return $this->terminal->isConfigured();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Opening the Link page
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The URL to send this carrier to, and the state that has to come back.
     *
     * A carrier whose connection has gone stale is sent to the re-auth page for
     * the connection they already have, not to a fresh flow — a new connection
     * to the same provider account would either be deduped into the old one or,
     * worse, become a second billable copy of the same fleet.
     */
    public function linkUrlFor(CarrierConnectRequest $connectRequest, string $redirectUrl): ?string
    {
        if (! $this->isConfigured()) {
            Log::error('Terminal is not configured; cannot open the ELD Link page.');

            return null;
        }

        $state = Str::random(48);

        $connectRequest->forceFill([
            'eld_link_state' => $state,
            'eld_link_state_at' => now(),
        ])->save();

        $template = $connectRequest->company?->eld_consent_template;

        $existing = $this->existingConnectionFor($connectRequest);

        if ($existing && $existing->status === EldConnection::STATUS_DISCONNECTED) {
            return $this->terminal->reauthUrl($existing->terminal_connection_id, [
                'redirect_url' => $redirectUrl,
                'state' => $state,
                'template' => $template,
            ]);
        }

        return $this->terminal->linkUrl([
            'redirect_url' => $redirectUrl,
            'state' => $state,

            // Shown to the carrier on the Link page and on their provider's
            // consent screen, so it has to read as the broker asking.
            'name' => $connectRequest->company?->company_name,

            'external_id' => $this->externalIdFor($connectRequest),
            'tags' => 'broker:'.$connectRequest->company_id,
            'template' => $template,
            'backfill_days' => config('services.terminal.backfill_days') ?: null,
        ]);
    }

    /**
     * Terminal's dedupe key, alongside the provider account.
     *
     * Deliberately the carrier's DOT number and nothing else. Put the broker in
     * here and the same fleet connects, imports and bills once per broker; keep
     * it to the carrier and the second broker's Link flow lands on the
     * connection that already exists.
     */
    public function externalIdFor(CarrierConnectRequest $connectRequest): string
    {
        return 'dot:'.trim((string) $connectRequest->carrier_dot_number);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Coming back from the Link page
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Finish the connection a carrier just made.
     *
     * The public token is single use and useless without the state, which is
     * checked against what we stored when the page was opened — so a return URL
     * replayed from a browser history, or one forged by a third party, gets
     * nowhere.
     */
    public function completeFromPublicToken(
        CarrierConnectRequest $connectRequest,
        string $publicToken,
        string $state
    ): ?EldConnection {
        if (! $this->stateMatches($connectRequest, $state)) {
            Log::warning('ELD link state mismatch', [
                'connect_request' => $connectRequest->uuid,
            ]);

            return null;
        }

        $payload = $this->terminal->exchangePublicToken($publicToken);

        if ($payload === null) {
            return null;
        }

        $connection = DB::transaction(function () use ($connectRequest, $payload) {
            $connection = $this->storeConnection($connectRequest, $payload);

            $this->grantTo($connection, $connectRequest);

            $connectRequest->forceFill([
                'eld_connection_id' => $connection->id,
                'eld_connected_at' => now(),

                // Single use: burn the nonce so the same return URL cannot be
                // played again.
                'eld_link_state' => null,
                'eld_link_state_at' => null,

                // A carrier who skipped and later came back has connected, and
                // the skip should stop being reported as their answer.
                'eld_skipped_at' => null,
            ])->save();

            return $connection;
        });

        // Outside the transaction: the job must not start against a connection
        // row the transaction has not committed yet.
        SyncEldConnection::dispatch($connection->id, true);

        return $connection;
    }

    /**
     * Write what Terminal told us about the connection.
     *
     * Matched on Terminal's own connection id first, because dedupe means a
     * second broker's flow returns the id we already hold — that is the case
     * this whole design exists to handle, and it must update, never insert.
     */
    public function storeConnection(CarrierConnectRequest $connectRequest, array $payload): EldConnection
    {
        $connection = EldConnection::firstOrNew([
            'terminal_connection_id' => $payload['id'],
        ]);

        $connection->fill([
            'uuid' => $connection->uuid ?: (string) Str::uuid(),
            'carrier_dot_number' => $connectRequest->carrier_dot_number,
            'carrier_row_id' => $connectRequest->carrier_row_id,
            'carrier_legal_name' => $connectRequest->carrier_legal_name,
            'external_id' => $payload['externalId'] ?? $this->externalIdFor($connectRequest),
            'provider' => data_get($payload, 'provider.name') ?: data_get($payload, 'provider.code'),
            'connection_token' => $payload['token'],
            'status' => $this->mapStatus($payload['status'] ?? EldConnection::STATUS_CONNECTED),
            'connected_at' => $connection->connected_at ?: now(),

            // A re-auth clears the break rather than leaving a stale timestamp
            // implying the connection is still down.
            'disconnected_at' => null,
        ]);

        if ($connection->sync_status === null) {
            $connection->sync_status = EldConnection::SYNC_PENDING;
        }

        $connection->save();

        return $connection;
    }

    /**
     * Record this broker's permission to see the carrier's fleet.
     *
     * Idempotent, and it un-revokes: a carrier who left a broker and came back
     * has consented again, and that is the same grant rather than a second one.
     */
    public function grantTo(EldConnection $connection, CarrierConnectRequest $connectRequest): EldConnectionGrant
    {
        $grant = EldConnectionGrant::firstOrNew([
            'eld_connection_id' => $connection->id,
            'company_id' => $connectRequest->company_id,
        ]);

        $grant->fill([
            'carrier_connect_request_id' => $connectRequest->id,
            'granted_at' => now(),
            'revoked_at' => null,
            'consent_template' => $connectRequest->company?->eld_consent_template,
        ])->save();

        return $grant;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wind-down
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One broker stops working with the carrier.
     *
     * Only that broker's grant goes; the connection keeps serving the others.
     * The connection itself is archived only once nobody is left looking at it,
     * because archiving it while another broker still has an active grant would
     * silently blind them.
     */
    public function revokeGrant(EldConnection $connection, int $companyId): void
    {
        EldConnectionGrant::where('eld_connection_id', $connection->id)
            ->where('company_id', $companyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        $stillWatched = EldConnectionGrant::where('eld_connection_id', $connection->id)
            ->whereNull('revoked_at')
            ->exists();

        if (! $stillWatched) {
            $this->archive($connection);
        }
    }

    /**
     * Stop syncing, keep the history.
     *
     * The default wind-down. A broker settling a dispute over a load already
     * hauled still needs the HOS and the positions from the day it ran, and
     * archiving stops the meter without throwing that away.
     */
    public function archive(EldConnection $connection): bool
    {
        if (! $this->terminal->updateConnection($connection->connection_token, ['status' => 'archived'])) {
            return false;
        }

        $connection->forceFill([
            'status' => EldConnection::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Erase it.
     *
     * Only for an explicit deletion request. Terminal answers with a 24-hour
     * grace period, and the local rows go with the connection through the
     * cascade — after that neither side can bring it back.
     */
    public function delete(EldConnection $connection): bool
    {
        if (! $this->terminal->deleteConnection($connection->connection_token)) {
            return false;
        }

        $connection->delete();

        return true;
    }

    /**
     * The carrier's provider credentials stopped working.
     *
     * Recorded, not deleted: the fleet history stays readable and the wizard
     * can offer re-auth against the same connection.
     */
    public function markDisconnected(EldConnection $connection): void
    {
        $connection->forceFill([
            'status' => EldConnection::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ])->save();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The connection this carrier already has, if any.
     *
     * Found by DOT number rather than by the connect request, because the whole
     * point is that a connection made during one broker's onboarding is the one
     * the next broker's onboarding should reuse.
     */
    public function existingConnectionFor(CarrierConnectRequest $connectRequest): ?EldConnection
    {
        if ($connectRequest->eld_connection_id) {
            return EldConnection::find($connectRequest->eld_connection_id);
        }

        $dot = trim((string) $connectRequest->carrier_dot_number);

        if ($dot === '') {
            return null;
        }

        return EldConnection::where('carrier_dot_number', $dot)
            ->whereNull('archived_at')
            ->latest('id')
            ->first();
    }

    private function stateMatches(CarrierConnectRequest $connectRequest, string $state): bool
    {
        if (! $connectRequest->eld_link_state || $state === '') {
            return false;
        }

        if ($connectRequest->eld_link_state_at?->lt(now()->subMinutes(self::STATE_TTL_MINUTES))) {
            return false;
        }

        return hash_equals($connectRequest->eld_link_state, $state);
    }

    /**
     * Terminal reports `pending_deletion` while a delete is in its grace
     * period, which is not one of our three — treat anything unrecognised as
     * disconnected so nothing keeps syncing against it.
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'connected' => EldConnection::STATUS_CONNECTED,
            'archived' => EldConnection::STATUS_ARCHIVED,
            default => EldConnection::STATUS_DISCONNECTED,
        };
    }
}
