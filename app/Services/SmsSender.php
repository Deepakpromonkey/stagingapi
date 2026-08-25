<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound SMS, via Telnyx.
 *
 * Every message this application sends is a one-time code somebody is sitting
 * and waiting for, so the only question that matters here is whether the
 * gateway actually took the message — a caller that gets `true` back tells the
 * user a code is on its way.
 *
 * The three flows that send codes (signup, carrier onboarding, driver sign-in)
 * used to carry a copy of this each. They drifted: only one of them checked the
 * per-message status, so the other two reported success on a message the
 * gateway had accepted and then dropped.
 */
class SmsSender
{
    private const ENDPOINT = 'https://api.telnyx.com/v2/messages';

    /**
     * Per-recipient statuses that mean the message is on its way.
     *
     * A synchronous send comes back `queued`; the rest are here because
     * delivery can outrun the response and they are all good news. Anything
     * else — `sending_failed`, `delivery_failed` — is a message that will not
     * arrive.
     */
    private const ACCEPTED = ['queued', 'sending', 'sent', 'delivered'];

    /**
     * Whether credentials are present.
     *
     * Callers check this before sending so each can decide what an
     * unconfigured gateway means for its own flow — in local that is usually
     * "log the code and carry on".
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.telnyx.key')
            && (config('services.telnyx.from') || config('services.telnyx.messaging_profile_id'));
    }

    /**
     * @param  string  $context  What is being sent, for the log line — e.g. "signup OTP".
     */
    public function send(string $to, string $body, string $context): bool
    {
        $payload = array_filter([
            'from' => config('services.telnyx.from'),
            'messaging_profile_id' => config('services.telnyx.messaging_profile_id'),
            'to' => $to,
            'text' => $body,
        ]);

        try {
            $response = Http::withToken(config('services.telnyx.key'))
                ->acceptJson()
                ->asJson()

                // Somebody's form submission is blocked on this call. Fail fast
                // rather than holding the request open on a silent gateway.
                ->timeout(10)
                ->post(self::ENDPOINT, $payload);

            if ($response->failed()) {
                Log::error('Telnyx rejected the '.$context, [
                    'to' => self::mask($to),
                    'status' => $response->status(),

                    // Only the errors array — the full body echoes the message
                    // text, and the message text is the code.
                    'errors' => $response->json('errors'),
                ]);

                return false;
            }

            /*
            | A 2xx means Telnyx took the request, not that this recipient was
            | accepted. An unreachable number or a sender not permitted on the
            | destination shows up here, and treating it as sent leaves the user
            | waiting on a code that is never coming.
            */
            $status = $response->json('data.to.0.status');

            if (! in_array($status, self::ACCEPTED, true)) {
                Log::error('Telnyx accepted the request but did not send the '.$context, [
                    'to' => self::mask($to),
                    'message_status' => $status,
                    'errors' => $response->json('data.errors'),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telnyx SMS threw sending the '.$context, [
                'to' => self::mask($to),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enough of the number to recognise it in a log, not enough to be a phone
     * number sitting in one.
     */
    public static function mask(string $phone): string
    {
        return strlen($phone) <= 4
            ? $phone
            : str_repeat('*', strlen($phone) - 4).substr($phone, -4);
    }
}
