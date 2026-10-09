<?php

namespace App\Services\Shipment;

use App\Models\Driver;
use App\Models\Shipment;
use App\Services\PushSender;
use App\Services\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tells the drivers on a new load that they have been put on it.
 *
 * Per phone number on the load: a push when that driver already has the
 * DollarTraq app, an SMS with the Play Store / App Store links when they
 * don't. Never both - a push that OneSignal did not deliver falls back to the
 * SMS, so every driver gets exactly one message.
 *
 * "Has the app" is read from app_drivers, the driver API's own table in the
 * same database: a driver who signed up there with this phone number and
 * whose phone gave the app a push subscription (device_token). Matched on the
 * last ten digits, the same rule the driver API uses to show a driver their
 * loads, so "this driver gets a push" and "this driver can see the load in
 * the app" can never disagree.
 */
class LoadAssignmentNotifier
{
    public function __construct(
        private readonly SmsSender $sms,
        private readonly PushSender $push,
    ) {}

    /**
     * The driver phones on a new load, as full international numbers, each
     * once.
     *
     * $countryCode1 is the dial code the booking form sent for phone 1
     * (country_code_1). The shipment does not keep it - only `country_code`,
     * which the form sends for phone 2 - so it has to be handed over from the
     * request that created the load.
     *
     * @return array<int, string>
     */
    public static function phonesFor(Shipment $shipment, ?string $countryCode1): array
    {
        $phones = array_filter([
            self::international($shipment->driver_phone_1, $countryCode1),
            self::international($shipment->driver_phone_2, $shipment->country_code),
            self::international($shipment->driver_phone_3, $shipment->country_code),
        ]);

        return array_values(array_unique($phones));
    }

    /**
     * "+1 (555) 010-2020" / "5550102020" + "+1" / "09876543210" + "+91"
     * -> "+15550102020" / "+15550102020" / "+919876543210".
     */
    public static function international(?string $phone, ?string $dialCode): ?string
    {
        $phone = trim((string) $phone);
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        // Already international - an ELD driver's phone arrives this way.
        if (str_starts_with($phone, '+')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        $dial = preg_replace('/\D+/', '', (string) $dialCode);

        if ($dial === '') {
            // No dial code to go on: the same default the rest of the app
            // applies to a bare ten digit number.
            return Driver::normalisePhone($phone);
        }

        // Typed with the country code but without the "+".
        if (strlen($digits) > 10 && str_starts_with($digits, $dial)) {
            return '+'.$digits;
        }

        // A national trunk prefix ("098765 43210" in India) is not part of
        // the international number.
        return '+'.$dial.ltrim($digits, '0');
    }

    /**
     * @param  array<int, string>  $phones  From phonesFor().
     */
    public function notify(Shipment $shipment, array $phones): void
    {
        $broker = $shipment->company?->company_name ?: 'Your broker';
        $load = $shipment->shipment_no;

        foreach ($phones as $phone) {
            try {
                $this->notifyOne($shipment, $phone, $broker, $load);
            } catch (\Throwable $e) {
                // One bad number must not cost the other drivers their message.
                Log::error('[LoadAssignment] failed for one driver', [
                    'shipment' => $shipment->uuid,
                    'to' => self::mask($phone),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyOne(Shipment $shipment, string $phone, string $broker, string $load): void
    {
        $subscription = $this->appSubscriptionFor($phone);

        if ($subscription !== null && $this->push->isConfigured()) {
            $route = $shipment->origin && $shipment->destination
                ? " ({$shipment->origin} to {$shipment->destination})"
                : '';

            $pushed = $this->push->send(
                $subscription,
                'New load assigned',
                "{$broker} assigned you load {$load}{$route}. Open DollarTraq to get started.",
                ['type' => 'load_assigned', 'shipment_uuid' => $shipment->uuid],
                'new load push',
            );

            if ($pushed) {
                Log::info('[LoadAssignment] push sent', ['shipment' => $shipment->uuid, 'to' => self::mask($phone)]);

                return;
            }
        }

        if (! $this->sms->isConfigured()) {
            Log::warning('[LoadAssignment] Telnyx is not configured; the driver was not told about the load', [
                'shipment' => $shipment->uuid,
                'to' => self::mask($phone),
            ]);

            return;
        }

        $sent = $this->sms->send($phone, $this->smsText($broker, $load), 'new load SMS');

        Log::info('[LoadAssignment] SMS '.($sent ? 'sent' : 'FAILED'), [
            'shipment' => $shipment->uuid,
            'to' => self::mask($phone),
        ]);
    }

    /**
     * Plain ASCII only: a single character outside the basic SMS alphabet
     * (a curly quote, an arrow) switches the whole text to UCS-2, which fits
     * 70 characters a segment instead of 160 - this message would then cost
     * four segments instead of two.
     */
    private function smsText(string $broker, string $load): string
    {
        return "DollarTraq: {$broker} assigned you load {$load}. "
            .'Download the DollarTraq app and sign up with this phone number to see it.'
            ."\nAndroid: ".config('driver_app.play_store_url')
            ."\niPhone: ".config('driver_app.app_store_url');
    }

    /**
     * The OneSignal subscription of the app driver with this number, if any.
     */
    private function appSubscriptionFor(string $phone): ?string
    {
        $last10 = substr(preg_replace('/\D+/', '', $phone), -10);

        if (strlen($last10) < 10) {
            return null;
        }

        try {
            $drivers = DB::table('app_drivers')
                ->where('phone', 'like', '%'.$last10)
                ->whereNotNull('device_token')
                ->where('device_token', '<>', '')
                ->orderByDesc('updated_at')
                ->get(['phone', 'device_token']);
        } catch (\Throwable $e) {
            // No app_drivers table where this runs: nobody has the app here.
            Log::warning('[LoadAssignment] could not look up app drivers: '.$e->getMessage());

            return null;
        }

        // The LIKE only narrows; this is the actual rule - same last ten
        // digits, whatever formatting either side was stored with.
        $match = $drivers->first(
            fn ($driver) => substr(preg_replace('/\D+/', '', (string) $driver->phone), -10) === $last10
        );

        return $match?->device_token;
    }

    private static function mask(string $phone): string
    {
        return str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4);
    }
}
