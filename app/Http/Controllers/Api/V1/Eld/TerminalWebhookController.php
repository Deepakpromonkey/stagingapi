<?php

namespace App\Http\Controllers\Api\V1\Eld;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEldConnection;
use App\Models\EldConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Terminal's webhooks.
 *
 * Public route — Terminal has no session here. Authenticity comes from the
 * signature, which is why verify() runs before anything is read out of the
 * body, and why a failure is a flat 401 rather than a helpful explanation.
 *
 * Terminal delivers through Svix, so the scheme is Svix's: HMAC-SHA256 over
 * "{svix-id}.{svix-timestamp}.{body}", keyed by the base64 half of the
 * whsec_ secret, compared against any of the space-separated versioned
 * signatures in svix-signature.
 *
 * Handlers must be idempotent. Terminal retries anything that is not 2xx, and
 * duplicate deliveries are explicitly expected.
 */
class TerminalWebhookController extends Controller
{
    /** How far out of step a delivery's timestamp may be before it is a replay. */
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request)
    {
        if (! $this->verify($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $type = $request->input('type');
        $detail = $request->input('detail', []);

        $terminalConnectionId = Arr::get($detail, 'connection.id');

        // Everything we act on names a connection. An event that does not is
        // one we have no state for, so acknowledge and move on rather than
        // making Terminal retry it forever.
        if (! $terminalConnectionId) {
            return response()->json(['received' => true]);
        }

        $connection = EldConnection::where('terminal_connection_id', $terminalConnectionId)->first();

        if (! $connection) {
            Log::info('Terminal webhook for an unknown connection', [
                'type' => $type,
                'connection' => $terminalConnectionId,
            ]);

            return response()->json(['received' => true]);
        }

        match (true) {
            // A sync finished at Terminal's end; ours is what pulls it across.
            in_array($type, ['sync.completed', 'connection.first_sync_completed'], true)
                => $this->onSyncCompleted($connection, $detail),

            $type === 'connection.disconnected' => $this->onDisconnected($connection),
            $type === 'connection.deleted' => $this->onDeleted($connection),
            $type === 'connection.reconnected' => $this->onReconnected($connection),

            // Fleet churn between scheduled passes. Cheaper to re-sync the
            // connection than to apply a partial diff, and ShouldBeUnique
            // collapses a burst of these into one job.
            str_starts_with((string) $type, 'vehicle.'),
            str_starts_with((string) $type, 'driver.')
                => SyncEldConnection::dispatchFor($connection),

            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function onSyncCompleted(EldConnection $connection, array $detail): void
    {
        $connection->forceFill(array_filter([
            'sync_status' => Arr::get($detail, 'sync.status'),
            'sync_progress' => Arr::get($detail, 'sync.progress'),
        ], fn ($value) => $value !== null))->save();

        SyncEldConnection::dispatchFor($connection);
    }

    private function onDisconnected(EldConnection $connection): void
    {
        $connection->forceFill([
            'status' => EldConnection::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ])->save();
    }

    private function onDeleted(EldConnection $connection): void
    {
        // The stored fleet stays. A broker looking at a completed onboarding
        // still needs to see what was true when the carrier was assessed.
        $connection->forceFill([
            'status' => EldConnection::STATUS_DELETED,
            'disconnected_at' => $connection->disconnected_at ?? now(),
        ])->save();
    }

    private function onReconnected(EldConnection $connection): void
    {
        $connection->forceFill([
            'status' => EldConnection::STATUS_CONNECTED,
            'disconnected_at' => null,
        ])->save();

        SyncEldConnection::dispatchFor($connection);
    }

    /**
     * Svix signature check.
     */
    private function verify(Request $request): bool
    {
        $secret = config('terminal.webhook_secret');

        if (! $secret) {
            Log::error('Terminal webhook secret is not configured; rejecting delivery.');

            return false;
        }

        $id = $request->header('svix-id');
        $timestamp = $request->header('svix-timestamp');
        $signatures = $request->header('svix-signature');

        if (! $id || ! $timestamp || ! $signatures) {
            return false;
        }

        // A signature stays valid forever without this, so a captured delivery
        // could be replayed at any point.
        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false) {
            Log::error('Terminal webhook secret is not valid base64.');

            return false;
        }

        $expected = base64_encode(
            hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true)
        );

        // The header carries space-separated "v1,<signature>" pairs so a secret
        // can be rotated without dropping deliveries signed by the old one.
        foreach (explode(' ', $signatures) as $candidate) {
            $parts = explode(',', $candidate, 2);

            if (count($parts) === 2 && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }
}
