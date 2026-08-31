<?php

namespace App\Services\Eld;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for Terminal's TSP API.
 *
 * Two auth layers, and mixing them up is the easy mistake:
 *
 *   Authorization: Bearer sk_...   identifies *us*, on every call
 *   Connection-Token: con_tkn_...  identifies *one carrier's* account
 *
 * Anything reading fleet data needs both. Only the token exchange needs the
 * secret key alone, because at that point no connection exists yet.
 */
class TerminalClient
{
    public function isConfigured(): bool
    {
        return (bool) config('terminal.enabled')
            && (bool) config('terminal.secret_key')
            && (bool) config('terminal.publishable_key');
    }

    public function baseUrl(): string
    {
        $environment = config('terminal.environment', 'sandbox');

        $url = config('terminal.base_urls.'.$environment);

        if (! $url) {
            throw new RuntimeException("Unknown Terminal environment [{$environment}].");
        }

        return $url;
    }

    public function linkBaseUrl(): string
    {
        $environment = config('terminal.environment', 'sandbox');

        $url = config('terminal.link_urls.'.$environment);

        if (! $url) {
            throw new RuntimeException("Unknown Terminal environment [{$environment}].");
        }

        return $url;
    }

    /**
     * The hosted page the carrier is sent to in order to pick their provider
     * and log in. Their credentials go to Terminal, never to us — which is the
     * entire reason for using a hosted flow rather than collecting them here.
     *
     * `state` comes back untouched on the redirect and is what proves the
     * return trip belongs to the onboarding request that started it.
     */
    public function linkUrl(array $params): string
    {
        $query = array_filter([
            'key' => config('terminal.publishable_key'),
            'redirect_url' => $params['redirect_url'] ?? null,
            'state' => $params['state'] ?? null,
            'external_id' => $params['external_id'] ?? null,
            'sync_mode' => $params['sync_mode'] ?? 'automatic',
            'backfill_days' => $params['backfill_days'] ?? config('terminal.backfill_days'),
            'provider' => $params['provider'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->linkBaseUrl().'/?'.http_build_query($query);
    }

    /**
     * Re-authentication for a connection whose credentials have gone stale —
     * the carrier changed their provider password, or revoked access.
     */
    public function reconnectUrl(string $connectionId, array $params): string
    {
        $query = array_filter([
            'key' => config('terminal.publishable_key'),
            'redirect_url' => $params['redirect_url'] ?? null,
            'state' => $params['state'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->linkBaseUrl().'/connection/'.urlencode($connectionId).'?'.http_build_query($query);
    }

    /**
     * Trade the short-lived public token the browser came back with for the
     * long-lived connection token. Must happen server-side: the public token is
     * exchangeable by anyone holding it.
     */
    public function exchangePublicToken(string $publicToken): array
    {
        $response = $this->request()
            ->post($this->baseUrl().'/public-token/exchange', [
                'publicToken' => $publicToken,
            ]);

        if ($response->failed()) {
            throw new TerminalRequestException(
                'Terminal rejected the public token exchange.',
                $response->status(),
                $response->body()
            );
        }

        return $response->json() ?? [];
    }

    /** The connection behind a given connection token. */
    public function currentConnection(string $connectionToken): array
    {
        return $this->get('/connections/current', $connectionToken);
    }

    /**
     * Walk a cursor-paginated collection to the end.
     *
     * Terminal answers `{results: [...], next: "<cursor>"}` and stops sending
     * `next` on the last page. The cap is a guard rather than a limit anyone
     * should hit — an unbounded loop against a paginated API is one bad
     * response away from running forever.
     */
    public function paginate(string $path, string $connectionToken, array $query = [], int $maxPages = 200): array
    {
        $results = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->get($path, $connectionToken, array_filter(array_merge($query, [
                'limit' => config('terminal.page_size'),
                'cursor' => $cursor,
            ]), fn ($value) => $value !== null && $value !== ''));

            foreach ($page['results'] ?? [] as $row) {
                $results[] = $row;
            }

            $cursor = $page['next'] ?? null;
            $pages++;
        } while ($cursor && $pages < $maxPages);

        return $results;
    }

    public function get(string $path, ?string $connectionToken = null, array $query = []): array
    {
        $request = $this->request();

        if ($connectionToken) {
            $request = $request->withHeaders(['Connection-Token' => $connectionToken]);
        }

        $response = $request->get($this->baseUrl().$path, $query);

        if ($response->failed()) {
            throw new TerminalRequestException(
                "Terminal request to {$path} failed.",
                $response->status(),
                $response->body()
            );
        }

        return $response->json() ?? [];
    }

    protected function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Terminal is not configured.');
        }

        return Http::withToken(config('terminal.secret_key'))
            ->acceptJson()
            ->timeout((int) config('terminal.timeout', 20))

            // Connection-level failures only. `throw: false` means a 4xx/5xx
            // never becomes an exception, so retry() never sees one — which is
            // what we want: a 401 is a bad key and retrying it three times just
            // makes the log worse. Rate limits and server errors surface as a
            // TerminalRequestException and are the sync job's to retry, with
            // backoff, rather than this method's to hide.
            ->retry(3, 500, throw: false);
    }
}
