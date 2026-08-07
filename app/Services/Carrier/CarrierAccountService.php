<?php

namespace App\Services\Carrier;

use App\Mail\CarrierAccountCreatedMail;
use App\Models\CarrierCompany;
use App\Models\CarrierConnectRequest;
use App\Models\CarrierLoginAttempt;
use App\Models\CarrierUser;
use App\Models\EmailTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carrier portal accounts.
 *
 * A carrier gets a login the moment they finish onboarding — the last step of
 * the wizard, not an invitation someone has to accept. The account is keyed on
 * the carrier's email, so a carrier who onboards with a second broker keeps the
 * single login (and the password they have already chosen) rather than being
 * mailed fresh credentials for an account they are already using.
 */
class CarrierAccountService
{
    public function __construct(
        protected CarrierTwoFactorService $carrierTwoFactorService,
        protected CarrierRoleService $carrierRoleService
    ) {}

    /**
     * Provision the login for a completed onboarding, and mail the carrier its
     * credentials.
     *
     * Safe to call more than once: the request is stamped with
     * `account_provisioned_at`, so a repeated e-sign cannot mail a second
     * password.
     */
    public function provisionFor(CarrierConnectRequest $connectRequest): ?CarrierUser
    {
        if ($connectRequest->status !== CarrierConnectRequest::STATUS_COMPLETED) {
            return null;
        }

        if ($connectRequest->portal_account_provisioned_at !== null) {
            return $connectRequest->carrierUser;
        }

        $email = strtolower(trim((string) $connectRequest->carrier_email));

        // Nothing to send an account to. The onboarding itself still stands —
        // the broker can chase the address and re-run this later.
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::error('Carrier onboarding completed without a usable email; no account created', [
                'connect_request' => $connectRequest->uuid,
            ]);

            $connectRequest->forceFill([
                'portal_account_error' => 'No usable email address is on file for this carrier.',
            ])->save();

            return null;
        }

        $temporaryPassword = null;

        $carrierUser = DB::transaction(function () use ($connectRequest, $email, &$temporaryPassword) {

            $account = $this->resolveAccount($connectRequest);

            $carrierUser = CarrierUser::where('email', $email)->first();

            if (! $carrierUser) {
                // Letters and digits only — this gets typed by hand out of an
                // email, often on a phone in a truck.
                $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false, spaces: false);

                $carrierUser = CarrierUser::create([
                    'uuid' => Str::uuid(),
                    'carrier_company_id' => $account->id,
                    'email' => $email,
                    'password' => Hash::make($temporaryPassword),
                    'must_change_password' => true,
                    'legal_name' => $connectRequest->carrier_legal_name,
                    'dot_number' => $connectRequest->carrier_dot_number,
                    'phone' => $connectRequest->carrier_phone,
                    'status' => true,

                    // The person who signed the agreement is the one who can
                    // sign, get paid, and seat everybody else.
                    'is_owner' => ! $account->users()->where('is_owner', true)->exists(),
                ]);

                // Reaching the end of the wizard means they opened the link we
                // mailed to this address, so it is verified by definition.
                $carrierUser->forceFill(['email_verified_at' => now()])->save();
            } elseif (! $carrierUser->carrier_company_id) {
                // A login that predates carrier accounts, onboarding again.
                $carrierUser->forceFill([
                    'carrier_company_id' => $account->id,
                    'is_owner' => ! $account->users()->where('is_owner', true)->exists(),
                ])->save();
            }

            // Owners provisioned by onboarding always hold the owner seat; an
            // existing login keeps whatever seat it already has.
            if ($carrierUser->is_owner && $carrierUser->roles->isEmpty()) {

                $ownerRole = $this->carrierRoleService->ownerRole();

                if ($ownerRole) {
                    $carrierUser->assignRole($ownerRole);
                }
            }

            $connectRequest->forceFill([
                'carrier_user_id' => $carrierUser->id,
                'portal_account_provisioned_at' => now(),
                'portal_account_email' => $carrierUser->email,
                'portal_account_error' => null,
            ])->save();

            return $carrierUser;
        });

        // An existing account keeps its own password — mailing a new one would
        // reset a carrier out of an account they are already signed into.
        if ($temporaryPassword === null) {
            Log::info('Carrier already had a portal account; credentials not re-sent', [
                'connect_request' => $connectRequest->uuid,
                'carrier_user' => $carrierUser->uuid,
            ]);

            return $carrierUser;
        }

        $this->sendCredentialsMail($carrierUser, $connectRequest, $temporaryPassword);

        return $carrierUser;
    }

    /**
     * The trucking company this onboarding belongs to.
     *
     * Keyed on DOT number, so a carrier who onboards with a second broker
     * lands in the account they already have — with the staff they already
     * invited — rather than a fresh one. Without a DOT number there is nothing
     * reliable to match on, so a new account is created.
     */
    protected function resolveAccount(CarrierConnectRequest $connectRequest): CarrierCompany
    {
        $dotNumber = $connectRequest->carrier_dot_number
            ? trim((string) $connectRequest->carrier_dot_number)
            : null;

        if ($dotNumber) {

            $account = CarrierCompany::where('dot_number', $dotNumber)->first();

            if ($account) {
                return $account;
            }
        }

        return CarrierCompany::create([
            'uuid' => Str::uuid(),
            'legal_name' => $connectRequest->carrier_legal_name,
            'dot_number' => $dotNumber,
            'phone' => $connectRequest->carrier_phone,
            'status' => true,
        ]);
    }

    /**
     * Uses the broker company's own carrier_account template when it has one,
     * and the packaged mailable otherwise. A delivery failure must not lose the
     * account that was just created, so it only logs — the carrier can still
     * recover the login through the broker.
     */
    protected function sendCredentialsMail(
        CarrierUser $carrierUser,
        CarrierConnectRequest $connectRequest,
        string $temporaryPassword
    ): void {
        $connectRequest->loadMissing(['company', 'user']);

        $portalUrl = self::loginUrl();

        $brokerName = $connectRequest->company->company_name;

        try {
            $template = EmailTemplate::forCompany($connectRequest->company_id)
                ->active()
                ->where('type', 'carrier_account')
                ->orderByDesc('is_default')
                ->first();

            if ($template) {
                $rendered = $template->render([
                    'carrier_name' => $carrierUser->displayName(),
                    'dot_number' => $connectRequest->carrier_dot_number,
                    'company_name' => $brokerName,
                    'email' => $carrierUser->email,
                    'temporary_password' => $temporaryPassword,
                    'portal_url' => $portalUrl,
                ]);

                Mail::html($rendered['body_html'], function ($message) use ($carrierUser, $rendered) {
                    $message->to($carrierUser->email)->subject($rendered['subject']);
                });
            } else {
                Mail::to($carrierUser->email)->send(new CarrierAccountCreatedMail(
                    $carrierUser,
                    $connectRequest,
                    $temporaryPassword,
                    $portalUrl,
                    $brokerName
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Carrier portal credentials email failed', [
                'connect_request' => $connectRequest->uuid,
                'email' => $carrierUser->email,
                'error' => $e->getMessage(),
            ]);

            // The account exists and works; only the mail carrying its password
            // did not arrive. Recorded so the broker can see it needs re-sending
            // rather than assuming the carrier simply has not signed in.
            $connectRequest->forceFill([
                'portal_account_error' => 'The account was created but its credentials email could not be delivered.',
            ])->save();
        }

        if (app()->environment('local')) {
            Log::info('=========== Carrier Portal Account ===========');
            Log::info('To', ['email' => $carrierUser->email]);
            Log::info('Temporary Password', ['password' => $temporaryPassword]);
            Log::info('Portal', ['url' => $portalUrl]);
            Log::info('==============================================');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Portal authentication
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Step 1 — check the credentials.
     *
     * A device the carrier has not asked us to remember gets an emailed code
     * instead of a token, and finishes at verifyLoginOtp(). Every call through
     * here is recorded with its IP, whatever the outcome.
     *
     * @return array{requires_otp: bool, otp_session?: string, expires_in_minutes?: int, token?: string, carrier_user?: CarrierUser}
     */
    public function login(array $data): array
    {
        $email = strtolower(trim($data['email']));

        $deviceUuid = $data['device_uuid'] ?? null;

        $carrierUser = CarrierUser::where('email', $email)->first();

        if (! $carrierUser) {
            CarrierLoginAttempt::record(
                CarrierLoginAttempt::OUTCOME_UNKNOWN_EMAIL,
                $email,
                null,
                $deviceUuid
            );

            // Same message either way — a wrong password and an address with no
            // account must not be tellable apart.
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if (! Hash::check($data['password'], $carrierUser->password)) {
            CarrierLoginAttempt::record(
                CarrierLoginAttempt::OUTCOME_BAD_PASSWORD,
                $email,
                $carrierUser,
                $deviceUuid
            );

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if (! $carrierUser->status) {
            CarrierLoginAttempt::record(
                CarrierLoginAttempt::OUTCOME_DISABLED,
                $email,
                $carrierUser,
                $deviceUuid
            );

            throw ValidationException::withMessages([
                'email' => ['This account has been disabled. Please contact your broker.'],
            ]);
        }

        if ($this->carrierTwoFactorService->requiresOtp($carrierUser, $deviceUuid)) {

            $otpData = $this->carrierTwoFactorService->generateOtp($carrierUser, $deviceUuid);

            CarrierLoginAttempt::record(
                CarrierLoginAttempt::OUTCOME_OTP_SENT,
                $email,
                $carrierUser,
                $deviceUuid
            );

            return [
                'requires_otp' => true,
                'otp_session' => $otpData['otp_session'],
                'expires_in_minutes' => $otpData['expires_in_minutes'],
            ];
        }

        // Trusted device — straight in, and the trust record is touched so an
        // account's device list shows real last-used dates.
        $this->carrierTwoFactorService
            ->trustedDevice($carrierUser, $deviceUuid)
            ?->forceFill([
                'last_used_at' => now(),
                'ip_address' => request()->ip(),
            ])->save();

        return [
            'requires_otp' => false,
        ] + $this->issueToken($carrierUser, $deviceUuid);
    }

    /**
     * Step 2 — check the emailed code and sign the carrier in.
     *
     * `remember_device` is what keeps this device off the challenge next time.
     *
     * @return array{token: string, carrier_user: CarrierUser}
     */
    public function verifyLoginOtp(array $data): array
    {
        try {
            $verified = $this->carrierTwoFactorService->verifyOtp($data);
        } catch (ValidationException $e) {
            CarrierLoginAttempt::record(
                CarrierLoginAttempt::OUTCOME_OTP_FAILED,
                null,
                null,
                $data['device_uuid'] ?? null
            );

            throw $e;
        }

        $carrierUser = $verified['carrier_user'];

        $deviceUuid = $verified['device_uuid'];

        if (! empty($data['remember_device']) && $deviceUuid) {
            $this->carrierTwoFactorService->trustDevice($carrierUser, $deviceUuid);
        }

        return $this->issueToken($carrierUser, $deviceUuid);
    }

    /**
     * Open a portal session, dropping whatever came before it.
     *
     * @return array{token: string, carrier_user: CarrierUser}
     */
    protected function issueToken(CarrierUser $carrierUser, ?string $deviceUuid): array
    {
        // One session at a time, matching the broker side: signing in here
        // signs the account out everywhere else.
        $carrierUser->tokens()->delete();

        $token = $carrierUser->createToken(
            'carrier-portal',
            [CarrierUser::TOKEN_ABILITY]
        )->plainTextToken;

        $carrierUser->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();

        CarrierLoginAttempt::record(
            CarrierLoginAttempt::OUTCOME_SUCCESS,
            $carrierUser->email,
            $carrierUser,
            $deviceUuid
        );

        return [
            'token' => $token,
            'carrier_user' => $carrierUser->fresh()->load('carrierCompany', 'roles.permissions'),
        ];
    }

    /**
     * This account's own sign-in history, most recent first.
     */
    public function loginHistory(CarrierUser $carrierUser, int $limit = 25)
    {
        return CarrierLoginAttempt::where('carrier_user_id', $carrierUser->id)
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Clears the temporary-password hold and drops every other session.
     */
    public function changePassword(CarrierUser $carrierUser, array $data): CarrierUser
    {
        if (! Hash::check($data['current_password'], $carrierUser->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $currentToken = $carrierUser->currentAccessToken();

        $carrierUser->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'last_password_changed_at' => now(),
        ])->save();

        $carrierUser->tokens()
            ->when($currentToken, fn ($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();

        return $carrierUser->fresh()->load('carrierCompany', 'roles.permissions');
    }

    /**
     * Replace the carrier's avatar. The old file goes with it, so storage
     * does not accumulate orphans — same as the broker side.
     */
    public function updateProfilePhoto(CarrierUser $carrierUser, UploadedFile $profileImage): CarrierUser
    {
        $previous = $carrierUser->profile_image;

        $carrierUser->forceFill([
            'profile_image' => $profileImage->store('carrier-profile-images', 's3'),
        ])->save();

        // Only once the new key is safely on the row: a failed delete must not
        // cost the carrier the picture they just uploaded.
        if ($previous) {
            Storage::disk('s3')->delete($previous);
        }

        return $carrierUser->fresh()->load('carrierCompany', 'roles.permissions');
    }

    /**
     * Drop the avatar; the portal falls back to initials.
     */
    public function removeProfilePhoto(CarrierUser $carrierUser): CarrierUser
    {
        if ($carrierUser->profile_image) {
            Storage::disk('s3')->delete($carrierUser->profile_image);
        }

        $carrierUser->forceFill(['profile_image' => null])->save();

        return $carrierUser->fresh()->load('carrierCompany', 'roles.permissions');
    }

    /**
     * Where a carrier signs in to the portal.
     *
     * Public and static because the onboarding resource shows the same link on
     * the completion screen — the address the carrier is mailed and the one
     * they are shown must not be able to drift apart.
     */
    public static function loginUrl(): string
    {
        return rtrim(config('carrier_connect.portal_url'), '/').'/login';
    }
}
