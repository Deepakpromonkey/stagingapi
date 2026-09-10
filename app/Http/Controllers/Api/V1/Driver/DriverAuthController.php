<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Driver;
use App\Models\DriverLoginOtp;
use App\Models\Shipment;
use App\Services\SmsSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phone sign-in for the driver app.
 *
 * A driver has no password: the phone number is the identity, so proving they
 * control it is the whole authentication. The number a broker typed onto a
 * shipment is the same number the driver signs in with, which is what lets a
 * driver see their loads without the broker inviting them to anything.
 */
class DriverAuthController extends BaseController
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Sends a sign-in code.
     *
     * The response never reveals whether the number is known or whether it is
     * on any shipment. Either would turn this endpoint into a way to enumerate
     * which drivers a brokerage works with, from an unauthenticated request.
     */
    public function requestOtp(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $phone = Driver::normalisePhone($request->input('phone'));

        if (! $phone) {
            return $this->error('Enter a valid phone number.', null, 422);
        }

        $lifetime = (int) config('carrier_connect.otp_lifetime_minutes', 15);

        // One live code per number: issuing a second without retiring the first
        // would leave two valid codes in circulation.
        DriverLoginOtp::where('phone_e164', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DriverLoginOtp::create([
            'phone_e164' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($lifetime),
            'ip_address' => $request->ip(),
        ]);

        $this->sendSms($phone, "Your DollarTraq driver code is {$code}. It expires in {$lifetime} minutes.");

        if (app()->environment('local')) {
            Log::info('=============== Driver Sign-in Code ===============');
            Log::info('To', ['phone' => $phone]);
            Log::info('Code', ['code' => $code]);
            Log::info('===================================================');
        }

        return $this->success(
            ['expires_in_minutes' => $lifetime],
            'If that number is registered to a driver, a code is on its way.'
        );
    }

    /**
     * Exchanges a code for an API token.
     *
     * The driver record is created on first successful sign-in rather than by
     * the broker, so there is no invitation step — being named on a load is
     * what makes someone a driver here.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'size:6'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $phone = Driver::normalisePhone($request->input('phone'));

        if (! $phone) {
            return $this->error('Enter a valid phone number.', null, 422);
        }

        $otp = DriverLoginOtp::where('phone_e164', $phone)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || $otp->expires_at->isPast()) {
            return $this->error('That code has expired. Request a new one.', null, 422);
        }

        $maxAttempts = (int) config('carrier_connect.otp_max_attempts', 3);

        if ($otp->attempts >= $maxAttempts) {
            // Burn it, so guessing cannot continue against the same code.
            $otp->forceFill(['consumed_at' => now()])->save();

            return $this->error('Too many incorrect attempts. Request a new code.', null, 429);
        }

        if (! Hash::check($request->input('code'), $otp->code_hash)) {
            $otp->increment('attempts');

            return $this->error('That code is not correct.', null, 422);
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        /*
        | A driver is only admitted if their number appears on at least one
        | shipment. Without this anyone could mint a token by verifying their
        | own phone, and while the shipment scope would still keep them out of
        | every load, they would hold a valid credential for an account nobody
        | created.
        */
        $named = Shipment::query()->forDriverPhone($phone)->exists();

        $driver = Driver::where('phone_e164', $phone)->first();

        if (! $driver && ! $named) {
            return $this->error(
                'This number is not on any active load. Ask your broker to add it to the shipment.',
                null,
                403
            );
        }

        $driver = $driver ?: new Driver(['uuid' => (string) Str::uuid(), 'phone_e164' => $phone]);

        if (! $driver->exists) {
            $driver->uuid = (string) Str::uuid();
            $driver->phone_e164 = $phone;
        }

        if (! $driver->is_active) {
            return $this->error('This driver account is no longer active.', null, 403);
        }

        $driver->fill([
            'name' => $request->input('name') ?: $driver->name,
            'phone_verified_at' => $driver->phone_verified_at ?: now(),
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // One live token per sign-in, so signing in on a new handset does not
        // leave the old one authorised indefinitely.
        $driver->tokens()->delete();

        $token = $driver->createToken('driver-app', [Driver::TOKEN_ABILITY])->plainTextToken;

        return $this->success([
            'token' => $token,
            'driver' => [
                'uuid' => $driver->uuid,
                'phone' => $driver->phone_e164,
                'name' => $driver->name,
            ],
        ], 'Signed in.');
    }

    public function me(Request $request)
    {
        $driver = $request->user();

        return $this->success([
            'uuid' => $driver->uuid,
            'phone' => $driver->phone_e164,
            'name' => $driver->name,
        ], 'Driver profile.');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Signed out.');
    }

    private function sendSms(string $to, string $body): bool
    {
        if (! $this->sms->isConfigured()) {
            Log::error('Telnyx is not configured; cannot send the driver sign-in code.');

            // Locally the code has just been written to the log, so sign-in is
            // still testable. Anywhere else, reporting success for a message
            // that was never sent leaves the driver waiting for nothing.
            return app()->environment('local');
        }

        return $this->sms->send($to, $body, 'driver sign-in code');
    }
}
