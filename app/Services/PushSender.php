<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push notifications to the DollarTraq driver app, via OneSignal.
 *
 * Addressed by subscription id: what the app registers with OneSignal on
 * sign-in, and what the driver API keeps on app_drivers.device_token. Same
 * OneSignal app as the driver API's own tracking alerts, so a driver has one
 * notification history, not two.
 *
 * Mirrors SmsSender's contract: `true` only when OneSignal accepted the
 * message for a real device. The caller falls back to SMS on `false`, so
 * "accepted the request but had nobody to deliver to" must read as a failure,
 * not a success.
 */
class PushSender
{
    private const ENDPOINT = 'https://api.onesignal.com/notifications?c=push';

    public function isConfigured(): bool
    {
        return (bool) config('services.onesignal.app_id')
            && (bool) config('services.onesignal.rest_api_key');
    }

    /**
     * @param  array<string, mixed>  $data  Delivered to the app with the notification, for it to act on when tapped.
     * @param  string  $context  What is being sent, for the log line - e.g. "new load push".
     */
    public function send(string $subscriptionId, string $heading, string $body, array $data, string $context): bool
    {
        try {
            $response = Http::withHeaders(['Authorization' => $this->authorization()])
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post(self::ENDPOINT, [
                    'app_id' => config('services.onesignal.app_id'),
                    'target_channel' => 'push',
                    'include_subscription_ids' => [$subscriptionId],
                    'headings' => ['en' => $heading],
                    'contents' => ['en' => $body],
                    'data' => $data,
                ]);

            /*
            | A 200 is not delivery. A subscription id that no longer belongs
            | to an installed app (uninstalled, signed out, push turned off)
            | comes back 200 with an empty id and an "errors" entry - that is a
            | driver who will never see this, and has to get the SMS instead.
            */
            if ($response->failed() || ! filled($response->json('id')) || filled($response->json('errors'))) {
                Log::warning('OneSignal did not deliver the '.$context, [
                    'subscription' => self::mask($subscriptionId),
                    'status' => $response->status(),
                    'errors' => $response->json('errors'),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('OneSignal push threw sending the '.$context, [
                'subscription' => self::mask($subscriptionId),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Current OneSignal keys (os_v2_...) authenticate as "Key", legacy ones
     * as "Basic" - the driver API's key is the current kind.
     */
    private function authorization(): string
    {
        $key = (string) config('services.onesignal.rest_api_key');

        return (str_starts_with($key, 'os_v2_') ? 'Key ' : 'Basic ').$key;
    }

    private static function mask(string $subscriptionId): string
    {
        return substr($subscriptionId, 0, 8).'…';
    }
}
