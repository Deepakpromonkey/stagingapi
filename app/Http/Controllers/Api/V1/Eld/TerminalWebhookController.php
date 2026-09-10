<?php

namespace App\Http\Controllers\Api\V1\Eld;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEldConnection;
use App\Models\Eld\EldConnection;
use App\Models\Eld\EldWebhookEvent;
use App\Services\Eld\EldConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Terminal's server-to-server events.
 *
 * The redirect back from the Link page is not a reliable signal — carriers
 * close the tab, lose signal in a yard, or hand the phone back before it
 * returns. These events are: they arrive whatever the browser did, and they
 * keep arriving afterwards when a connection breaks or a sync finishes.
 *
 * Public by necessity, so the signature check below is the only thing standing
 * between this endpoint and anyone who knows the URL.
 */
class TerminalWebhookController extends Controller
{
    /**
     * Terminal delivers through Svix, whose signature covers the id, the
     * timestamp and the exact bytes of the body.
     */
    private const TOLERANCE_SECONDS = 300;

    public function __construct(private EldConnectionService $connections) {}

    public function handle(Request $request)
    {
        if (! $this->signatureIsValid($request)) {
            // 401 rather than 4xx-with-detail: an unverified caller learns
            // nothing about why it failed.
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();

        $eventId = (string) data_get($payload, 'id');
        $type = (string) data_get($payload, 'type');

        if ($eventId === '' || $type === '') {
            return response()->json(['error' => 'Malformed event'], 422);
        }

        $connectionId = (string) (data_get($payload, 'detail.connection.id') ?? '');

        /*
        | Idempotency, and the reason this answers 2xx on a repeat. A retry of
        | something already handled must not sync twice — Terminal bills for
        | what gets read.
        */
        $event = EldWebhookEvent::firstOrNew(['event_id' => $eventId]);

        if ($event->exists && $event->processed_at !== null) {
            return response()->json(['status' => 'duplicate']);
        }

        $event->fill([
            'type' => $type,
            'terminal_connection_id' => $connectionId ?: null,
            'payload' => $payload,
        ])->save();

        $this->dispatchEvent($type, $connectionId, $payload);

        $event->forceFill(['processed_at' => now()])->save();

        return response()->json(['status' => 'ok']);
    }

    private function dispatchEvent(string $type, string $connectionId, array $payload): void
    {
        $connection = $connectionId !== ''
            ? EldConnection::where('terminal_connection_id', $connectionId)->first()
            : null;

        switch ($type) {
            case 'connection.created':
                /*
                | Usually already stored: the carrier's browser came back first
                | and the exchange ran on the request. When it did not — a
                | closed tab, a dropped redirect — this is the only notice we
                | get, and without the connection token that the exchange
                | returns there is nothing to store. Logged loudly rather than
                | swallowed, because it means a carrier believes they are
                | connected and we do not agree.
                */
                if (! $connection) {
                    Log::warning('Terminal connection.created for a connection we never exchanged', [
                        'terminal_connection_id' => $connectionId,
                        'external_id' => data_get($payload, 'detail.connection.externalId'),
                    ]);
                }

                break;

            case 'connection.disconnected':
                if ($connection) {
                    $this->connections->markDisconnected($connection);
                }

                break;

            case 'sync.completed':
            case 'vehicle.added':
            case 'driver.added':
                /*
                | New data is waiting. The job is checkpointed and throttled to
                | one pass per connection per ten minutes, so a provider that
                | adds forty trucks at once produces one sync, not forty.
                */
                if ($connection?->isSyncable()) {
                    SyncEldConnection::dispatch($connection->id);
                }

                break;

            case 'sync.failed':
                if ($connection) {
                    $connection->forceFill([
                        'sync_status' => EldConnection::SYNC_FAILED,
                        'last_sync_error' => (string) (data_get($payload, 'detail.sync.error')
                            ?: 'Terminal reported a failed sync.'),
                    ])->save();
                }

                break;

            case 'safety_event.added':
                /*
                | Recorded but not yet acted on: there is no safety_events table
                | on this side, and inventing one here would be scope creep. The
                | event row above keeps the payload, so nothing is lost when the
                | risk-alert feed is built against it. See docs/eld-terminal.md.
                */
                break;

            default:
                // Delivery events and anything Terminal adds later. Answering
                // 2xx stops the retries; ignoring the body is deliberate.
                break;
        }
    }

    /**
     * Svix signature check.
     *
     * The signed content is `id.timestamp.body`, HMAC-SHA256 with the raw bytes
     * of the signing secret, compared in constant time. The timestamp bound is
     * what stops a captured delivery being replayed days later.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('services.terminal.webhook_secret');

        if ($secret === '') {
            // Refusing beats trusting: an endpoint that accepts unverified
            // payloads can be told a connection is fine when it is not.
            Log::error('Terminal webhook secret is not configured; rejecting delivery.');

            return false;
        }

        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');

        if ($id === '' || $timestamp === '' || $signatures === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        // `whsec_` prefixes a base64 secret; the HMAC is over its raw bytes.
        $key = base64_decode(substr($secret, 0, 6) === 'whsec_' ? substr($secret, 6) : $secret, true);

        if ($key === false) {
            Log::error('Terminal webhook secret is not valid base64.');

            return false;
        }

        $expected = base64_encode(
            hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true)
        );

        /*
        | The header carries a space-separated list — Svix sends every currently
        | valid signature during a secret rotation, so matching any one of them
        | is correct.
        */
        foreach (explode(' ', $signatures) as $candidate) {
            $parts = explode(',', $candidate, 2);

            if (count($parts) === 2 && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }
}
