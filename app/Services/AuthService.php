<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LoginDevice;
use App\Models\Role;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        protected TwoFactorAuthService $twoFactorAuthService
    ) {}

    public function register(array $data)
    {
        return DB::transaction(function () use ($data) {

            // Create Company
            $company = Company::create([
                'uuid' => Str::uuid(),
                'company_name' => $data['company_name'],
                'company_email' => $data['email'],
                'company_phone' => $data['phone'] ?? null,
            ]);

            // Create Owner User
            $user = User::create([
                'uuid' => Str::uuid(),
                'company_id' => $company->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'is_owner' => true,
                'status' => true,
            ]);

            // Update company creator
            $company->update([
                'created_by' => $user->id,
            ]);

            // The signup user owns the company: top seat, full risk authority.
            $user->assignRole(
                Role::where('slug', config('rbac.owner_role'))->firstOrFail()
            );

            // Generate Sanctum Token
            $token = $user->createToken('broker-api')->plainTextToken;

            return [
                'token' => $token,
                'user' => $user->load('company', 'roles.permissions', 'permissions'),
            ];
        });
    }

    public function login(array $data)
    {
        $user = User::with(['company', 'roles.permissions', 'permissions'])
            ->where('email', $data['email'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if ($this->twoFactorAuthService->requiresOtp(
            $user,
            $data['device_uuid'] ?? null
        )) {

            $otpData = $this->twoFactorAuthService->generateOtp(
                $user,
                request()->ip()
            );

            return [
                'requires_otp' => true,
                'otp_session' => $otpData['otp_session'],
            ];
        }

        // Single device login
        $user->tokens()->delete();

        $token = $user->createToken('broker-api')->plainTextToken;

        return [
            'requires_otp' => false,
            'token' => $token,
            'user' => $user,
        ];
    }

    /**
     * Change the password of the signed-in user. Clears the temporary-password
     * hold and revokes every other session.
     */
    public function changePassword(User $user, array $data): User
    {
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $currentToken = $user->currentAccessToken();

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'last_password_changed_at' => now(),
        ])->save();

        // Keep the caller signed in, drop everything else.
        $user->tokens()
            ->when($currentToken, fn ($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();

        return $user->fresh()->load('company', 'roles.permissions', 'permissions');
    }

    public function verifyLoginOtp(array $data)
    {
        $user = $this->twoFactorAuthService->verifyOtp($data);

        // One Device Login
        $user->tokens()->delete();

        // Update Login Information
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        // Remember Device
        if (! empty($data['remember_device']) && ! empty($data['device_uuid'])) {

            TrustedDevice::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_uuid' => $data['device_uuid'],
                ],
                [
                    'device_name' => request()->userAgent(),
                    'browser' => null,
                    'platform' => null,
                    'ip_address' => request()->ip(),
                    'last_used_at' => now(),
                    'expires_at' => now()->addYear(),
                ]
            );
        }

        // Login History
        LoginDevice::create([
            'user_id' => $user->id,
            'device_name' => request()->userAgent(),
            'browser' => null,
            'platform' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'is_current' => true,
            'last_login_at' => now(),
        ]);

        $token = $user->createToken('broker-api')->plainTextToken;

        return [
            'token' => $token,
            'user' => $user->fresh()->load('company', 'roles.permissions', 'permissions'),
        ];
    }

    public function updateProfile(User $user, array $data, ?UploadedFile $profileImage = null): User
    {
        if ($profileImage) {

            // Remove the old file so storage doesn't accumulate orphans.
            if ($user->profile_image) {
                Storage::disk('s3')->delete($user->profile_image);
            }

            $data['profile_image'] = $profileImage->store('profile-images', 's3');
        }

        $user->fill(
            collect($data)->only(['first_name', 'last_name', 'phone', 'profile_image'])->toArray()
        )->save();

        return $user->fresh()->load('company', 'roles.permissions', 'permissions');
    }
}
