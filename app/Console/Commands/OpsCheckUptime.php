<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks the production sites from outside, every minute, on the watcher box.
 *
 * Down means a connection failure, a timeout, or a 5xx. A 4xx is the site
 * answering, so it counts as up. One alert when a site goes down (after
 * ops.uptime.failures_before_alert misses in a row), a reminder while it stays
 * down, and one more when it is back.
 */
class OpsCheckUptime extends Command
{
    protected $signature = 'ops:check-uptime';

    protected $description = 'Alert when a production site or API stops answering';

    public function handle(OpsAlert $alerts): int
    {
        $cache = Cache::store('file');
        $threshold = max(1, (int) config('ops.uptime.failures_before_alert', 2));
        $remindAfter = max(1, (int) config('ops.remind_after_minutes', 60)) * 60;

        foreach (config('ops.uptime.targets', []) as $url) {
            $key = 'ops-uptime:'.sha1($url);
            $state = $cache->get($key, ['failures' => 0, 'down_since' => null, 'alerted_at' => null]);

            [$up, $detail, $ms] = $this->probe($url);

            if ($up) {
                if ($state['down_since']) {
                    $alerts->send(OpsAlert::RESOLVED, 'Back up: '.$this->name($url), [
                        'URL' => $url,
                        'Was down for' => $this->duration(now()->getTimestamp() - $state['down_since']),
                        'Response' => $detail.' in '.$ms.' ms',
                    ], 'uptime|'.$url.'|up', force: true);
                }

                $cache->forever($key, ['failures' => 0, 'down_since' => null, 'alerted_at' => null]);
                $this->line("up    {$url} ({$detail}, {$ms} ms)");

                continue;
            }

            $state['failures']++;
            $this->line("DOWN  {$url} ({$detail})");

            if ($state['failures'] >= $threshold) {
                $state['down_since'] ??= now()->getTimestamp();

                if (! $state['alerted_at'] || now()->getTimestamp() - $state['alerted_at'] >= $remindAfter) {
                    $alerts->send(OpsAlert::CRITICAL, 'DOWN: '.$this->name($url), [
                        'URL' => $url,
                        'Problem' => $detail,
                        'Down for' => $this->duration(now()->getTimestamp() - $state['down_since']),
                        'Checked from' => config('ops.server_label').' (outside production)',
                    ], 'uptime|'.$url.'|down', force: true);

                    $state['alerted_at'] = now()->getTimestamp();
                }
            }

            $cache->forever($key, $state);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: bool, 1: string, 2: int}
     */
    private function probe(string $url): array
    {
        $started = microtime(true);

        try {
            $response = Http::timeout((int) config('ops.uptime.timeout', 15))
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'DollarTraq-Uptime/1.0'])
                ->get($url);

            $ms = (int) round((microtime(true) - $started) * 1000);

            return [$response->status() < 500, 'HTTP '.$response->status(), $ms];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $started) * 1000);

            return [false, \Illuminate\Support\Str::limit($e->getMessage(), 200), $ms];
        }
    }

    private function name(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: $url;
    }

    private function duration(int $seconds): string
    {
        return $seconds < 60
            ? $seconds.'s'
            : \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
