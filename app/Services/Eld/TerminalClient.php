<?php

namespace App\Services\Eld;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Terminal's TSP API — the transport layer, and nothing else.
 *
 * Two credentials with two jobs. The publishable key is public by design and
 * only ever appears in the Link URL the carrier's browser follows; the secret
 * key authorises every call made from here. Data calls need both: the secret
 * key says which of Terminal's customers is asking, the connection token says
 * whose fleet is being asked about.
 *
 * Environment lives entirely in config — swapping the four values in
 * `services.terminal` moves the whole integration between sandbox and
 * production without a code change. See docs/eld-terminal.md.
 */
class TerminalClient
{
    /**
     * Terminal caps a page and may return fewer rows than asked for, so this is
     * a ceiling rather than a promise. Paging is driven by `next`, never by
     * counting what came back.
     */
    private const PAGE_SIZE = 100;

    public function isConfigured(): bool
    {
        return (bool) config('services.terminal.enabled')
            && filled(config('services.terminal.secret_key'))
            && filled(config('services.terminal.publishable_key'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consent flow
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The hosted Link page a carrier is sent to.
     *
     * `external_id` is normally absent, and the reasoning it used to carry here
     * was wrong, so it is worth stating plainly: Terminal matches connections
     * on the provider plus the provider's own account identifier. An external
     * id is NOT part of that match. It can only ever split a connection, never
     * join one — two different values against a single account fork it, while a
     * value missing on either side still matches.
     *
     * So the duplicate this once claimed to prevent was never possible, and
     * sending the field on every link risked causing one. Terminal confirmed
     * the behaviour directly: a carrier connected through one broker who later
     * goes through another broker's flow and signs into the same provider
     * account lands on the existing connection, with nothing passed at link
     * time.
     *
     * If a Sandbox test appears to contradict that, check Settings > General >
     * Dedupe Connections. It is on by default in Production and OFF by default
     * in Sandbox, so every Sandbox link yields a fresh connection until it is
     * switched on. That default is what the original reasoning here was built
     * on.
     *
     * The one case that does want a value is a single provider login covering
     * two of our carriers; EldConnectionService::linkUrlFor() forks that
     * deliberately.
     */
    public function linkUrl(array $params): string
    {
        $query = array_filter([
            'key' => config('services.terminal.publishable_key'),
            'redirect_url' => $params['redirect_url'],
            'state' => $params['state'] ?? null,
            'name' => $params['name'] ?? null,
            'external_id' => $params['external_id'] ?? null,
            'tags' => $params['tags'] ?? null,
            'template' => $params['template'] ?? null,
            'backfill_days' => $params['backfill_days'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return rtrim((string) config('services.terminal.link_url'), '/')
            .'/?'.http_build_query($query);
    }

    /**
     * The same page, aimed at an existing connection.
     *
     * The connection id sits in the path, and the carrier must sign in to the
     * same provider account — re-auth repairs a connection, it does not move it
     * to a different one.
     */
    public function reauthUrl(string $connectionId, array $params): string
    {
        $query = array_filter([
            'key' => config('services.terminal.publishable_key'),
            'redirect_url' => $params['redirect_url'],
            'state' => $params['state'] ?? null,
            'template' => $params['template'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return rtrim((string) config('services.terminal.link_url'), '/')
            .'/connection/'.$connectionId.'?'.http_build_query($query);
    }

    /**
     * Trade the single-use public token from the redirect for the connection.
     *
     * Returns Terminal's FullConnection, whose `token` is the long-lived
     * credential everything else here needs.
     */
    public function exchangePublicToken(string $publicToken): ?array
    {
        $response = $this->client()
            ->post($this->url('/public-token/exchange'), ['publicToken' => $publicToken]);

        if ($response->failed()) {
            $this->logFailure('public token exchange', $response);

            return null;
        }

        $payload = $response->json();

        // A response without these two is not something to store: the id is how
        // every webhook refers to the connection, the token is the only way to
        // read its data.
        if (empty($payload['id']) || empty($payload['token'])) {
            Log::error('Terminal exchange returned no connection', [
                'keys' => array_keys((array) $payload),
            ]);

            return null;
        }

        return $payload;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Connection lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    public function connection(string $connectionToken): ?array
    {
        $response = $this->client($connectionToken)->get($this->url('/connections/current'));

        if ($response->failed()) {
            $this->logFailure('connection fetch', $response);

            return null;
        }

        return $response->json();
    }

    /**
     * Change a connection in place — status, filters, tags, sync mode.
     *
     * Archiving goes through here (`['status' => 'archived']`): it stops the
     * sync and the meter while leaving the history a broker may still need to
     * settle a dispute over a load already hauled.
     */
    public function updateConnection(string $connectionToken, array $attributes): bool
    {
        $response = $this->client($connectionToken)
            ->patch($this->url('/connections/current'), $attributes);

        if ($response->failed()) {
            $this->logFailure('connection update', $response);

            return false;
        }

        return true;
    }

    /**
     * Ask Terminal to erase the connection and its data.
     *
     * Answered with 202 and a 24-hour grace period, during which
     * updateConnection() can still put the status back. Only for an explicit
     * erasure request — everything else archives.
     */
    public function deleteConnection(string $connectionToken): bool
    {
        $response = $this->client($connectionToken)->delete($this->url('/connections/current'));

        if ($response->failed()) {
            $this->logFailure('connection delete', $response);

            return false;
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Walk every page of a list endpoint, handing each page to $onPage.
     *
     * Paging stops on the absence of `next`, never on a short page — providers
     * routinely return fewer rows than the limit asked for, and counting would
     * end the walk early and silently lose the tail of a fleet.
     *
     * @param  callable(array):void  $onPage
     * @return int the number of records seen
     */
    public function paginate(string $connectionToken, string $path, array $query, callable $onPage): int
    {
        $cursor = null;
        $seen = 0;

        // A connection whose provider is having a bad day should not spin here
        // forever; 500 pages is far past any real fleet.
        for ($page = 0; $page < 500; $page++) {
            $response = $this->client($connectionToken)->get(
                $this->url($path),
                array_filter(array_merge($query, [
                    'limit' => self::PAGE_SIZE,
                    'cursor' => $cursor,
                ]), fn ($value) => $value !== null && $value !== '')
            );

            if ($response->failed()) {
                $this->logFailure('list '.$path, $response);

                /*
                | 403 is an entitlement, not a fault: Terminal names the
                | permission the account is missing — `hos:read`, say — and will
                | answer the same way on every retry. Raised as its own type so
                | the sync can skip the resource instead of failing the pass and
                | losing the resources that did work.
                */
                if ($response->status() === 403) {
                    throw new TerminalPermissionException(
                        (string) ($response->json('detail')
                            ?: 'Terminal refused '.$path.' for this account.')
                    );
                }

                throw new TerminalRequestException(
                    'Terminal returned '.$response->status().' for '.$path
                );
            }

            $results = (array) $response->json('results', []);

            if ($results !== []) {
                $onPage($results);
                $seen += count($results);
            }

            $cursor = $response->json('next');

            if (! $cursor) {
                break;
            }
        }

        return $seen;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function client(?string $connectionToken = null): PendingRequest
    {
        $request = Http::withToken(config('services.terminal.secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(30)

            /*
            | Terminal proxies live provider APIs, so a slow provider surfaces
            | as a 429 or a 504 here. Retrying twice costs nothing and saves a
            | sync from failing over a blip.
            |
            | The pause between attempts is configurable so the test suite can
            | set it to zero — otherwise every test that exercises a failure
            | sits through the real backoff, which turned a six second suite
            | into a thirty five second one.
            */
            ->retry(2, (int) config('services.terminal.retry_delay_ms', 500), throw: false);

        if ($connectionToken !== null) {
            $request = $request->withHeaders(['Connection-Token' => $connectionToken]);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.terminal.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function logFailure(string $what, Response $response): void
    {
        Log::error('Terminal '.$what.' failed', [
            'status' => $response->status(),

            // Truncated: a fleet listing failure can return a very large body,
            // and the first part is always where the error is.
            'body' => Str::limit($response->body(), 500),
        ]);
    }

        /**
     * The latest known position of each vehicle, live from the provider.
     *
     * Unlike /vehicles/{id}/locations this is not a history read — it hits the
     * provider's API on every call and answers with one row per vehicle. That
     * is what makes it the right endpoint for an in-flight load: a single call
     * covers every truck a carrier is currently hauling for us, where the
     * history walk is a call per truck per pass.
     *
     * Terminal filters at most 50 vehicles per request, so the ids are chunked.
     * Passing none asks for the whole fleet, which is only ever what a debug
     * call wants — the poller always names its trucks.
     *
     * Vehicles with no last known position are simply absent from the response
     * rather than returned empty, so a short result is normal and not an error.
     */
    public function latestVehicleLocations(string $connectionToken, array $vehicleIds = []): array
    {
        $results = [];

        $collect = function (array $rows) use (&$results) {
            foreach ($rows as $row) {
                $results[] = $row;
            }
        };

        if ($vehicleIds === []) {
            $this->paginate($connectionToken, '/vehicles/locations', ['expand' => 'driver'], $collect);

            return $results;
        }

        foreach (array_chunk(array_values(array_unique($vehicleIds)), 50) as $chunk) {
            $this->paginate(
                $connectionToken,
                '/vehicles/locations',
                [
                    'vehicleIds' => implode(',', $chunk),

                    // The driver on the truck right now, which is not always
                    // the driver the broker picked at booking — a carrier can
                    // swap one before the load rolls.
                    'expand' => 'driver',
                ],
                $collect
            );
        }

        return $results;
    }
    
}
