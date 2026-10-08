<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Checks this box's own services every minute: nginx, PHP-FPM, MySQL, Redis,
 * Supervisor. One alert when a service stops, a reminder while it stays
 * stopped, and one more when it is running again.
 *
 * Runs from the scheduler, which needs none of them - so a stopped PHP-FPM or
 * MySQL is still noticed. CPU, memory and disk are already covered by
 * /usr/local/bin/dt-resource-alert.sh.
 */
class OpsCheckServices extends Command
{
    protected $signature = 'ops:check-services';

    protected $description = 'Alert when nginx, PHP-FPM, MySQL, Redis or Supervisor stops';

    public function handle(OpsAlert $alerts): int
    {
        $cache = Cache::store('file');
        $remindAfter = max(1, (int) config('ops.remind_after_minutes', 60)) * 60;

        foreach (config('ops.services', []) as $service) {
            $result = Process::timeout(10)->run(['systemctl', 'is-active', $service]);
            $status = trim($result->output()) ?: 'unknown';
            $running = $status === 'active';

            $key = 'ops-service:'.$service;
            $state = $cache->get($key, ['down_since' => null, 'alerted_at' => null]);

            if ($running) {
                if ($state['down_since']) {
                    $alerts->send(OpsAlert::RESOLVED, "Running again: {$service}", [
                        'Service' => $service,
                        'Was stopped for' => $this->minutes(now()->getTimestamp() - $state['down_since']),
                    ], 'service|'.$service.'|up', force: true);
                }

                $cache->forget($key);
                $this->line("ok    {$service}");

                continue;
            }

            $state['down_since'] ??= now()->getTimestamp();
            $this->line("DOWN  {$service} ({$status})");

            if (! $state['alerted_at'] || now()->getTimestamp() - $state['alerted_at'] >= $remindAfter) {
                $alerts->send(OpsAlert::CRITICAL, "Service stopped: {$service}", [
                    'Service' => $service,
                    'State' => $status,
                    'Stopped for' => $this->minutes(now()->getTimestamp() - $state['down_since']),
                    'Check' => "sudo systemctl status {$service}",
                ], 'service|'.$service.'|down', force: true);

                $state['alerted_at'] = now()->getTimestamp();
            }

            $cache->forever($key, $state);
        }

        return self::SUCCESS;
    }

    private function minutes(int $seconds): string
    {
        return max(1, (int) round($seconds / 60)).' min';
    }
}
