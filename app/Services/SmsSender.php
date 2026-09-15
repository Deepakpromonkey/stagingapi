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
     * Numbers whose country must not be decided by the caller.
     *
     * Each flow normalises to E.164 against one configured dial code —
     * `carrier_connect.default_dial_code` for onboarding, `signup` for the
     * rest — and on production both are `+1`. A ten digit Indian number given
     * to any of them comes out as `+1` plus the same ten digits, which is a
     * real US number belonging to somebody else. Pinning the country here
     * fixes it once, at the point every message already passes through,
     * rather than in each flow's own normaliser.
     *
     * These are test handsets. Remove an entry once the number is stored in
     * full `+91` form everywhere it is read from.
     */
    private const FORCED_COUNTRY = [
        ['national' => '8076734039', 'dial' => '+91'],
    ];

    /**
     * Pins a listed number to its real country, whatever shape it arrives in.
     *
     * Matches the bare national number and any already-prefixed form, because
     * the case this exists to correct is a number that has *already* been
     * given the wrong dial code upstream.
     *
     * Only the prefixes this application itself produces are accepted. A
     * trailing-digit match on its own would also rewrite a foreign number
     * that happens to end the same way — +44 8076734039, say — and that is
     * somebody else's handset.
     */
    public static function forceCountry(string $to): string
    {
        $digits = preg_replace('/\D/', '', $to);

        // An IDD prefix written out in full, as 0091... rather than +91...
        $digits = preg_replace('/^00/', '', $digits);

        foreach (self::FORCED_COUNTRY as $number) {
            $national = $number['national'];

            if (! str_ends_with($digits, $national)) {
                continue;
            }

            $prefix = substr($digits, 0, -strlen($national));

            /*
            | Nothing at all, the right country already, or the `+1` that both
            | `carrier_connect.default_dial_code` and `signup.default_dial_code`
            | stamp on a bare ten digit number in production — which is the
            | case this whole method exists to undo.
            */
            $dial = ltrim($number['dial'], '+');

            if (in_array($prefix, ['', $dial, '0'.$dial, '1'], true)) {
                return $number['dial'].$national;
            }
        }

        return $to;
    }

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
        $to = self::forceCountry($to);

        [$to, $body] = $this->applyOverride($to, $body, $context);

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
     * Redirects every message to one handset, for testing.
     *
     * Staging carries real carrier records with real phone numbers on them, and
     * walking the onboarding wizard means passing a phone check the tester
     * cannot receive. Rather than editing a carrier's number to their own —
     * which changes the data being tested and leaves the wrong number behind —
     * `SMS_OVERRIDE_TO` sends the lot to one number and says who each was for.
     *
     * Unset in production, where this must never be on: it would divert real
     * carriers' one-time codes to whoever the number belongs to. It is logged
     * as a warning on every send so an environment that has it on by accident
     * says so loudly rather than quietly misdelivering.
     *
     * @return array{0: string, 1: string} the recipient and body to send
     */
    private function applyOverride(string $to, string $body, string $context): array
    {
        $override = trim((string) config('services.telnyx.override_to'));

        if ($override === '' || $override === $to) {
            return [$to, $body];
        }

        Log::warning('SMS override is on; redirecting the '.$context, [
            'intended' => self::mask($to),
            'sent_to' => self::mask($override),
        ]);

        /*
        | Which carrier the code belongs to, so a tester running several
        | onboardings at once can tell the messages apart. Masked, because the
        | point is to identify the recipient and not to put a full phone number
        | into somebody else's message history.
        */
        return [$override, '[test → '.self::mask($to).'] '.$body];
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
