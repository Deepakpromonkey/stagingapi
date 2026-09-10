<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LoginDevice;
use App\Models\Role;
use App\Models\TrustedDevice;
use App\Models\SignupOtp;
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
        protected TwoFactorAuthService $twoFactorAuthService,
        protected SignupOtpService $signupOtpService
    ) {}

    /*
    | The form sends the dial code separately from the digits the user typed,
    | so neither field on its own is the number a code was sent to. Joined
    | here; the service normalises the result to E.164 before comparing.
    */
    private function fullPhone(array $data): string
    {
        $phone = trim((string) ($data['phone'] ?? ''));

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        $dial = trim((string) ($data['phone_country_code'] ?? ''));

        return $dial === '' ? $phone : $dial.$phone;
    }

    public function register(array $data)
    {
        /*
        | Both proofs are resolved before the transaction opens, so a token
        | that is expired, already spent, or issued for a different address
        | fails the request before a company and a user have been created.
        |
        | They are only marked consumed once everything else has succeeded —
        | a signup that falls over on some later field must leave the visitor
        | able to press the button again without re-verifying.
        */
        $emailProof = $this->signupOtpService->resolveProof(
            SignupOtp::CHANNEL_EMAIL,
            $data['email_verification_token'],
            $data['email']
        );

        $phoneProof = $this->signupOtpService->resolveProof(
            SignupOtp::CHANNEL_PHONE,
            $data['phone_verification_token'],
            $this->fullPhone($data)
        );

        return DB::transaction(function () use ($data, $emailProof, $phoneProof) {

            // Create Company
            $company = Company::create([
                'uuid' => Str::uuid(),
                'company_name' => $data['company_name'],
                'company_email' => $data['email'],
                'company_phone' => $data['phone'] ?? null,
                'business_type' => $data['business_type'] ?? null,
                'dot_number' => $data['dot_number'] ?? null,
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

            // Spent, so neither proof can open a second account.
            $emailProof->forceFill(['consumed_at' => now()])->save();
            $phoneProof->forceFill(['consumed_at' => now()])->save();

            // Generate Sanctum Token
            $token = $user->createToken('broker-api')->plainTextToken;

            return [
                'token' => $token,
                'user' => $user->load('company.subscription', 'roles.permissions', 'permissions'),
            ];
        });
    }

    public function login(array $data)
    {
        $user = User::with(['company.subscription', 'roles.permissions', 'permissions'])
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

        return $user->fresh()->load('company.subscription', 'roles.permissions', 'permissions');
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
            'user' => $user->fresh()->load('company.subscription', 'roles.permissions', 'permissions'),
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
            collect($data)->only(['first_name', 'last_name', 'phone','country_code', 'profile_image'])->toArray()
        )->save();

        return $user->fresh()->load('company.subscription', 'roles.permissions', 'permissions');
    }
}
