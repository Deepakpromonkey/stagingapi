<?php

namespace App\Services\Ops;

use App\Mail\OpsAlertMail;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends an operational alert to the Teams channel and by email.
 *
 * Every alert carries a fingerprint. The same fingerprint is sent at most once
 * per ops.repeat_after_minutes; repeats inside that window are counted and the
 * count goes out with the next one. State is kept in the file cache on
 * purpose: the alert most worth sending is "the database is down", and the
 * database cache store would be down with it.
 *
 * Nothing here may throw. An alert that cannot be delivered is logged, and
 * whatever raised it carries on.
 */
class OpsAlert
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const RESOLVED = 'resolved';

    public static function enabled(): bool
    {
        return filled(config('ops.role'));
    }

    /**
     * @param  array<string, scalar|null>  $facts
     * @param  bool  $force  skip the repeat window - recoveries and reminders
     *                       decide their own timing
     */
    public function send(string $level, string $title, array $facts, string $fingerprint, bool $force = false): bool
    {
        if (! self::enabled()) {
            return false;
        }

        try {
            $cache = $this->cache();
            $key = 'ops-alert:'.sha1($fingerprint);
            $now = now()->getTimestamp();
            $state = $cache->get($key);
            $window = max(1, (int) config('ops.repeat_after_minutes', 15)) * 60;

            if (! $force && $state && $now - $state['sent_at'] < $window) {
                $state['suppressed']++;
                $cache->put($key, $state, now()->addDay());

                return false;
            }

            if (! $this->underHourlyCap($cache)) {
                Log::warning('Ops alert dropped: hourly cap reached', ['title' => $title]);

                return false;
            }

            if (! empty($state['suppressed'])) {
                $facts['Repeats'] = $state['suppressed'].' more time(s) since the last alert';
            }

            $cache->put($key, ['sent_at' => $now, 'suppressed' => 0], now()->addDay());

            $facts = array_merge(['Server' => config('ops.server_label')], $facts, [
                'When' => now()->utc()->format('Y-m-d H:i:s').' UTC',
            ]);

            $this->postToTeams($level, $title, $facts);
            $this->email($level, $title, $facts);

            return true;
        } catch (Throwable $e) {
            Log::warning('Ops alert could not be sent', ['title' => $title, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * An exception the app reported. Only what locates the fault is sent -
     * never the request body, headers or query string, which can hold
     * passwords, tokens and carrier data.
     */
    public function exception(Throwable $e, ?Request $request = null): bool
    {
        $file = Str::after($e->getFile(), base_path().DIRECTORY_SEPARATOR);

        $facts = [
            'Error' => class_basename($e),
            'Message' => Str::limit(trim($e->getMessage()) ?: '(no message)', 500),
            'Where' => $file.':'.$e->getLine(),
        ];

        if ($request?->route()) {
            // The route pattern, not the path: paths carry invitation tokens.
            $facts['Request'] = $request->method().' /'.ltrim($request->route()?->uri() ?? $request->path(), '/');

            if ($user = $request->user()) {
                $facts['User'] = '#'.$user->getAuthIdentifier()
                    .(isset($user->company_id) ? ' (company #'.$user->company_id.')' : '');
            }
        } elseif (app()->runningInConsole()) {
            $facts['Context'] = 'Console: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3));
        }

        return $this->send(
            self::CRITICAL,
            'Server error: '.class_basename($e),
            $facts,
            'exception|'.get_class($e).'|'.$file.'|'.$e->getLine(),
        );
    }

    private function postToTeams(string $level, string $title, array $facts): void
    {
        $url = (string) config('services.teams.webhook_url');

        if ($url === '') {
            return;
        }

        $response = Http::timeout(5)->asJson()->post($url, [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        [
                            'type' => 'TextBlock',
                            'text' => $this->icon($level).' '.$title,
                            'weight' => 'Bolder',
                            'size' => 'Medium',
                            'color' => match ($level) {
                                self::CRITICAL => 'Attention',
                                self::WARNING => 'Warning',
                                default => 'Good',
                            },
                            'wrap' => true,
                        ],
                        [
                            'type' => 'FactSet',
                            'facts' => collect($facts)
                                ->map(fn ($value, $name) => ['title' => (string) $name, 'value' => (string) $value])
                                ->values()
                                ->all(),
                        ],
                    ],
                ],
            ]],
        ]);

        if ($response->failed()) {
            // The URL is a credential and stays out of the log.
            Log::warning('Ops alert rejected by Teams', ['status' => $response->status()]);
        }
    }

    private function email(string $level, string $title, array $facts): void
    {
        $to = config('ops.email.to', []);

        if ($to === []) {
            return;
        }

        Mail::to($to)
            ->cc(config('ops.email.cc', []))
            ->send(new OpsAlertMail($this->icon($level).' '.$title, $level, $facts));
    }

    private function icon(string $level): string
    {
        return match ($level) {
            self::CRITICAL => '🔴',
            self::WARNING => '🟡',
            default => '✅',
        };
    }

    private function underHourlyCap(Repository $cache): bool
    {
        $key = 'ops-alert-count:'.now()->format('YmdH');
        $count = (int) $cache->get($key, 0);

        if ($count >= (int) config('ops.max_per_hour', 40)) {
            return false;
        }

        $cache->put($key, $count + 1, now()->addHours(2));

        return true;
    }

    private function cache(): Repository
    {
        return Cache::store('file');
    }
}
