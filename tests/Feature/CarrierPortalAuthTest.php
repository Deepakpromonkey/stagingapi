<?php

namespace Tests\Feature;

use App\Mail\LoginOtpMail;
use App\Mail\PasswordResetOtpMail;
use App\Models\CarrierLoginAttempt;
use App\Models\CarrierTrustedDevice;
use App\Models\CarrierUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CarrierPortalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function carrier(array $attributes = []): CarrierUser
    {
        return CarrierUser::create(array_merge([
            'uuid' => Str::uuid(),
            'email' => 'dispatch@acmetrucking.test',
            'password' => Hash::make('secret-password'),
            'must_change_password' => false,
            'legal_name' => 'Acme Trucking LLC',
            'status' => true,
        ], $attributes));
    }

    /**
     * The code that was mailed, read back off the fake.
     */
    protected function mailedLoginOtp(): string
    {
        $otp = null;

        Mail::assertSent(LoginOtpMail::class, function (LoginOtpMail $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        return $otp;
    }

    public function test_login_from_an_unknown_device_is_challenged_instead_of_signed_in(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        $response = $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonMissingPath('data.token');

        Mail::assertSent(LoginOtpMail::class);

        $this->assertSame(0, $carrier->tokens()->count());

        $this->assertDatabaseHas('carrier_login_attempts', [
            'carrier_user_id' => $carrier->id,
            'outcome' => CarrierLoginAttempt::OUTCOME_OTP_SENT,
            'device_uuid' => 'device-one',
        ]);
    }

    public function test_verifying_the_otp_signs_in_and_remembers_the_device(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        $session = $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->json('data.otp_session');

        $response = $this->postJson('/api/v1/carrier-portal/verify-login-otp', [
            'otp_session' => $session,
            'otp' => $this->mailedLoginOtp(),
            'remember_device' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.carrier_user.email', $carrier->email);

        $this->assertNotEmpty($response->json('data.token'));

        // Trusted, and the challenge is spent.
        $this->assertDatabaseHas('carrier_trusted_devices', [
            'carrier_user_id' => $carrier->id,
            'device_uuid' => 'device-one',
        ]);

        $this->assertDatabaseCount('carrier_login_otps', 0);

        $this->assertDatabaseHas('carrier_login_attempts', [
            'carrier_user_id' => $carrier->id,
            'outcome' => CarrierLoginAttempt::OUTCOME_SUCCESS,
        ]);

        $this->assertNotNull($carrier->fresh()->last_login_at);
        $this->assertNotNull($carrier->fresh()->last_login_ip);
    }

    public function test_a_remembered_device_skips_the_otp(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        CarrierTrustedDevice::create([
            'carrier_user_id' => $carrier->id,
            'device_uuid' => 'device-one',
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ]);

        $response->assertOk()->assertJsonPath('data.requires_otp', false);

        $this->assertNotEmpty($response->json('data.token'));

        Mail::assertNothingSent();
    }

    public function test_an_expired_trust_is_challenged_again(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        CarrierTrustedDevice::create([
            'carrier_user_id' => $carrier->id,
            'device_uuid' => 'device-one',
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->assertOk()->assertJsonPath('data.requires_otp', true);
    }

    public function test_signing_in_drops_the_previous_session(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        CarrierTrustedDevice::create([
            'carrier_user_id' => $carrier->id,
            'device_uuid' => 'device-one',
            'expires_at' => now()->addDays(30),
        ]);

        $first = $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->json('data.token');

        $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->assertOk();

        // Exactly one live token, and it is not the first one.
        $this->assertSame(1, $carrier->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/v1/carrier-portal/me')
            ->assertUnauthorized();
    }

    public function test_a_wrong_password_is_rejected_and_recorded_with_its_ip(): void
    {
        $carrier = $this->carrier();

        $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $attempt = CarrierLoginAttempt::latest('id')->first();

        $this->assertSame(CarrierLoginAttempt::OUTCOME_BAD_PASSWORD, $attempt->outcome);
        $this->assertSame($carrier->id, $attempt->carrier_user_id);
        $this->assertNotNull($attempt->ip_address);
    }

    public function test_an_attempt_on_an_unknown_email_is_still_recorded(): void
    {
        $this->postJson('/api/v1/carrier-portal/login', [
            'email' => 'nobody@nowhere.test',
            'password' => 'whatever',
        ])->assertStatus(422);

        $this->assertDatabaseHas('carrier_login_attempts', [
            'carrier_user_id' => null,
            'email' => 'nobody@nowhere.test',
            'outcome' => CarrierLoginAttempt::OUTCOME_UNKNOWN_EMAIL,
        ]);
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        $session = $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->json('data.otp_session');

        $this->postJson('/api/v1/carrier-portal/verify-login-otp', [
            'otp_session' => $session,
            'otp' => '000000',
        ])->assertStatus(422);

        $this->assertDatabaseHas('carrier_login_otps', [
            'otp_session' => $session,
            'attempts' => 1,
        ]);

        $this->assertDatabaseHas('carrier_login_attempts', [
            'outcome' => CarrierLoginAttempt::OUTCOME_OTP_FAILED,
        ]);
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $carrier = $this->carrier(['status' => false]);

        $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('carrier_login_attempts', [
            'carrier_user_id' => $carrier->id,
            'outcome' => CarrierLoginAttempt::OUTCOME_DISABLED,
        ]);
    }

    public function test_forgotten_password_can_be_reset_with_an_emailed_code(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        $carrier->createToken('carrier-portal', [CarrierUser::TOKEN_ABILITY]);

        $session = $this->postJson('/api/v1/carrier-portal/forgot-password', [
            'email' => $carrier->email,
        ])->assertOk()->json('data.otp_session');

        $otp = null;

        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $resetToken = $this->postJson('/api/v1/carrier-portal/verify-forgot-password-otp', [
            'otp_session' => $session,
            'otp' => $otp,
        ])->assertOk()->json('data.reset_token');

        $this->postJson('/api/v1/carrier-portal/reset-password', [
            'reset_token' => $resetToken,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $carrier->refresh();

        $this->assertTrue(Hash::check('brand-new-password', $carrier->password));

        // Every session is signed out by a reset.
        $this->assertSame(0, $carrier->tokens()->count());

        // Single use.
        $this->postJson('/api/v1/carrier-portal/reset-password', [
            'reset_token' => $resetToken,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422);
    }

    public function test_forgot_password_does_not_reveal_whether_the_email_exists(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/carrier-portal/forgot-password', [
            'email' => 'nobody@nowhere.test',
        ])->assertOk()->assertJsonStructure(['data' => ['otp_session']]);

        Mail::assertNothingSent();

        $this->assertDatabaseCount('carrier_password_reset_otps', 0);
    }

    public function test_a_carrier_can_list_and_forget_their_devices(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        CarrierTrustedDevice::create([
            'carrier_user_id' => $carrier->id,
            'device_uuid' => 'device-one',
            'expires_at' => now()->addDays(30),
        ]);

        $token = $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/carrier-portal/devices')
            ->assertOk()
            ->assertJsonPath('data.0.device_uuid', 'device-one');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/carrier-portal/devices/forget', ['device_uuid' => 'device-one'])
            ->assertOk();

        $this->assertDatabaseCount('carrier_trusted_devices', 0);

        // Challenged again now that the trust is gone.
        $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->assertOk()->assertJsonPath('data.requires_otp', true);
    }

    public function test_login_history_shows_this_accounts_attempts_only(): void
    {
        Mail::fake();

        $carrier = $this->carrier();

        $other = $this->carrier(['email' => 'someone@other.test']);

        CarrierLoginAttempt::create([
            'carrier_user_id' => $other->id,
            'email' => $other->email,
            'outcome' => CarrierLoginAttempt::OUTCOME_BAD_PASSWORD,
            'ip_address' => '10.0.0.9',
        ]);

        CarrierTrustedDevice::create([
            'carrier_user_id' => $carrier->id,
            'device_uuid' => 'device-one',
            'expires_at' => now()->addDays(30),
        ]);

        $token = $this->postJson('/api/v1/carrier-portal/login', [
            'email' => $carrier->email,
            'password' => 'secret-password',
            'device_uuid' => 'device-one',
        ])->json('data.token');

        $history = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/carrier-portal/login-history')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $history);
        $this->assertSame(CarrierLoginAttempt::OUTCOME_SUCCESS, $history[0]['outcome']);
        $this->assertNotNull($history[0]['ip_address']);
    }

    public function test_a_broker_token_cannot_reach_the_portal(): void
    {
        $this->getJson('/api/v1/carrier-portal/me')->assertUnauthorized();
    }
}
