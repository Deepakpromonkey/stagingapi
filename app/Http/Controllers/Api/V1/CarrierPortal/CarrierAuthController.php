<?php

namespace App\Http\Controllers\Api\V1\CarrierPortal;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\CarrierPortal\CarrierChangePasswordRequest;
use App\Http\Requests\CarrierPortal\CarrierForgetDeviceRequest;
use App\Http\Requests\CarrierPortal\CarrierLoginRequest;
use App\Http\Requests\CarrierPortal\CarrierVerifyLoginOtpRequest;
use App\Http\Resources\CarrierUserResource;
use App\Services\Carrier\CarrierAccountService;
use App\Services\Carrier\CarrierTwoFactorService;

/**
 * Sign-in for carriers who have finished onboarding.
 *
 * The account is created by the onboarding wizard, not here — there is no
 * signup. A carrier arrives with the credentials mailed to them at the end of
 * the flow, and cannot reach anything else until they have replaced the
 * temporary password (EnsurePasswordChanged).
 *
 * Sign-in is two steps unless the device is already trusted: credentials, then
 * an emailed code. Every attempt is recorded with its IP.
 */
class CarrierAuthController extends BaseController
{
    public function __construct(
        protected CarrierAccountService $carrierAccountService,
        protected CarrierTwoFactorService $carrierTwoFactorService
    ) {}

    public function login(CarrierLoginRequest $request)
    {
        $data = $this->carrierAccountService->login($request->validated());

        // Unrecognised device — no token yet, the code has to clear first.
        if ($data['requires_otp']) {

            return $this->success([
                'requires_otp' => true,
                'otp_session' => $data['otp_session'],
                'expires_in_minutes' => $data['expires_in_minutes'],
            ], 'OTP has been sent to your registered email.');
        }

        return $this->success([
            'requires_otp' => false,
            'token' => $data['token'],
            'carrier_user' => new CarrierUserResource($data['carrier_user']),
        ], 'Login Successful');
    }

    /**
     * Second step of sign-in. Pass `remember_device` to skip the code on this
     * device next time.
     */
    public function verifyLoginOtp(CarrierVerifyLoginOtpRequest $request)
    {
        $data = $this->carrierAccountService->verifyLoginOtp($request->validated());

        return $this->success([
            'token' => $data['token'],
            'carrier_user' => new CarrierUserResource($data['carrier_user']),
        ], 'Login Successful');
    }

    /**
     * The devices this carrier is remembered on.
     */
    public function devices()
    {
        $devices = $this->carrierTwoFactorService
            ->trustedDevices(auth()->user())
            ->map(fn ($device) => [
                'device_uuid' => $device->device_uuid,
                'device_name' => $device->device_name,
                'ip_address' => $device->ip_address,
                'last_used_at' => $device->last_used_at?->toIso8601String(),
                'expires_at' => $device->expires_at?->toIso8601String(),
            ])->values();

        return $this->success($devices);
    }

    /**
     * Stop trusting a device, so it is challenged again next time.
     */
    public function forgetDevice(CarrierForgetDeviceRequest $request)
    {
        $this->carrierTwoFactorService->forgetDevice(
            $request->user(),
            $request->validated()['device_uuid']
        );

        return $this->success(null, 'Device removed. It will be asked for a code next time.');
    }

    /**
     * Recent sign-in attempts on this account, with the IP each came from.
     */
    public function loginHistory()
    {
        $history = $this->carrierAccountService
            ->loginHistory(auth()->user())
            ->map(fn ($attempt) => [
                'outcome' => $attempt->outcome,
                'ip_address' => $attempt->ip_address,
                'user_agent' => $attempt->user_agent,
                'device_uuid' => $attempt->device_uuid,
                'attempted_at' => $attempt->created_at?->toIso8601String(),
            ])->values();

        return $this->success($history);
    }

    public function me()
    {
        $carrierUser = auth()->user()->load(
            'carrierCompany',
            'roles.permissions'
        );

        return $this->success(
            new CarrierUserResource($carrierUser)
        );
    }

    public function logout()
    {
        auth()->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * Set a new password. Carriers land here first, with the temporary password
     * from their account email as `current_password`.
     */
    public function changePassword(CarrierChangePasswordRequest $request)
    {
        $carrierUser = $this->carrierAccountService->changePassword(
            $request->user(),
            $request->validated()
        );

        return $this->success(
            new CarrierUserResource($carrierUser),
            'Password changed successfully.'
        );
    }
}
