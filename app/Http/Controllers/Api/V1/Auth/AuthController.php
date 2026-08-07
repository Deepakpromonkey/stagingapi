<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyLoginOtpRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\AuthService;

class AuthController extends BaseController
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function signup(SignupRequest $request)
    {
        $data = $this->authService->register(
            $request->validated()
        );

        return $this->success([
            'token' => $data['token'],
            'user' => new AuthUserResource($data['user']),
        ], 'Registration Successful', 201);
    }

    public function login(LoginRequest $request)
    {
        $data = $this->authService->login(
            $request->validated()
        );

        // OTP Required
        if ($data['requires_otp']) {

            return $this->success([
                'requires_otp' => true,
                'otp_session' => $data['otp_session'],
            ], 'OTP has been sent to your registered email.');
        }

        // Direct Login
        return $this->success([
            'requires_otp' => false,
            'token' => $data['token'],
            'user' => new AuthUserResource($data['user']),
        ], 'Login Successful');
    }

    public function me()
    {
        $user = auth()->user()->load([
            'company',
            'roles.permissions',
            'permissions',
        ]);

        return $this->success(
            new AuthUserResource($user)
        );
    }

    public function logout()
    {
        auth()->user()->currentAccessToken()->delete();

        return $this->success(
            null,
            'Logged out successfully.'
        );
    }

    public function verifyLoginOtp(VerifyLoginOtpRequest $request)
    {
        $data = $this->authService->verifyLoginOtp(
            $request->validated()
        );

        return $this->success([
            'token' => $data['token'],
            'user' => new AuthUserResource($data['user']),
        ], 'Login Successful');
    }

    /**
     * Set a new password. Invited users land here first, with the temporary
     * password from their invitation email as `current_password`.
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $this->authService->changePassword(
            $request->user(),
            $request->validated()
        );

        return $this->success(
            new AuthUserResource($user),
            'Password changed successfully.'
        );
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $this->authService->updateProfile(
            $request->user(),
            $request->validated(),
            $request->file('profile_image')
        );

        return $this->success(
            new AuthUserResource($user),
            'Profile updated successfully.'
        );
    }
}
