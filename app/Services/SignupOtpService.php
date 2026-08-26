<?php

namespace App\Services;

use App\Mail\SignupOtpMail;
use App\Models\SignupOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-time codes that prove a signup form's email address and phone number.
 *
 * The account does not exist yet, so there is no user to hang these off. The
 * address itself is the subject: a code is issued for one destination, and the
 * proof it produces is only accepted at signup for that same destination.
 */
class SignupOtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Issue a code and send it.
     *
     * @return array{otp_session: string, destination: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function send(string $channel, string $destination, ?string $ipAddress): array
    {
        $destination = $this->normalise($channel, $destination);

        $this->guardCooldown($channel, $destination);

        /*
        | Any code already outstanding for this address is retired first.
        |
        | Without this, a resend leaves two live codes and the older one keeps
        | working — which quietly doubles the window an intercepted SMS is
        | useful for, and makes "the code I was just sent doesn't work" a
        | genuine possibility when the user types the newer of the two.
        */
        SignupOtp::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->pending()
            ->update(['expires_at' => now()]);

        $otp = (string) random_int(100000, 999999);

        $minutes = (int) config('signup.otp_lifetime_minutes', 10);

        $record = SignupOtp::create([
            'otp_session' => (string) Str::uuid(),
            'channel' => $channel,
            'destination' => $destination,

            // Hashed. A leaked database should not hand over live codes.
            'otp' => Hash::make($otp),

            'expires_at' => now()->addMinutes($minutes),
            'ip_address' => $ipAddress,
        ]);

        $delivered = $channel === SignupOtp::CHANNEL_EMAIL
            ? $this->sendEmail($destination, $otp, $minutes)
            : $this->sendSms($destination, $otp, $minutes);

        /*
        | A code nobody received is worse than an error: the visitor sits
        | waiting on a message that is never going to arrive. Retire it and
        | say so, rather than reporting success.
        */
        if (! $delivered) {
            $record->forceFill(['expires_at' => now()])->save();

            throw ValidationException::withMessages([
                'destination' => $channel === SignupOtp::CHANNEL_EMAIL
                    ? 'We could not send the code to that email address. Please check it and try again.'
                    : 'We could not send the code to that number. Please check it and try again.',
            ]);
        }

        return [
            'otp_session' => $record->otp_session,
            'destination' => $destination,
            'expires_at' => $record->expires_at,
        ];
    }

    /**
     * Check a code and, on success, issue the proof signup will ask for.
     *
     * @return array{verification_token: string, channel: string, destination: string}
     */
    public function verify(string $otpSession, string $otp): array
    {
        $record = SignupOtp::where('otp_session', $otpSession)->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'otp' => 'That verification session is no longer valid. Request a new code.',
            ]);
        }

        if ($record->verified_at) {
            throw ValidationException::withMessages([
                'otp' => 'That code has already been used. Request a new one.',
            ]);
        }

        if ($record->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'otp' => 'That code has expired. Request a new one.',
            ]);
        }

        $maxAttempts = (int) config('signup.otp_max_attempts', 5);

        if ($record->attempts >= $maxAttempts) {
            throw ValidationException::withMessages([
                'otp' => 'Too many incorrect attempts. Request a new code.',
            ]);
        }

        if (! Hash::check($otp, $record->otp)) {
            $record->increment('attempts');

            $remaining = $maxAttempts - $record->attempts;

            throw ValidationException::withMessages([
                'otp' => $remaining > 0
                    ? 'That code is not correct. '.$remaining.' attempt'.($remaining === 1 ? '' : 's').' left.'
                    : 'That code is not correct. Request a new one.',
            ]);
        }

        /*
        | The client is handed the plain token; only its hash is stored. Same
        | reasoning as the code above — and it means the lookup at signup is an
        | indexed equality check rather than a scan.
        */
        $token = Str::random(64);

        $record->forceFill([
            'verified_at' => now(),
            'verification_token' => hash('sha256', $token),
        ])->save();

        return [
            'verification_token' => $token,
            'channel' => $record->channel,
            'destination' => $record->destination,
        ];
    }

    /**
     * Spend a proof at signup.
     *
     * Returns the row so the caller can mark it consumed inside its own
     * transaction — a token must not be burned by a signup that then fails
     * validation on some other field.
     */
    public function resolveProof(string $channel, string $token, string $destination): SignupOtp
    {
        $destination = $this->normalise($channel, $destination);

        $record = SignupOtp::query()
            ->where('channel', $channel)
            ->where('verification_token', hash('sha256', $token))
            ->unspent()
            ->first();

        $field = $channel === SignupOtp::CHANNEL_EMAIL ? 'email' : 'phone';

        if (! $record) {
            throw ValidationException::withMessages([
                $field => 'Verify your '.$field.' before creating the account.',
            ]);
        }

        $lifetime = (int) config('signup.token_lifetime_minutes', 60);

        if ($record->verified_at->copy()->addMinutes($lifetime)->isPast()) {
            throw ValidationException::withMessages([
                $field => 'That verification has expired. Please verify your '.$field.' again.',
            ]);
        }

        /*
        | The proof is bound to the address it was issued for. Without this
        | check a visitor could verify one address, change the field, and sign
        | up with an address nobody ever confirmed — which is the entire thing
        | this flow exists to prevent.
        */
        if (! hash_equals($record->destination, $destination)) {
            throw ValidationException::withMessages([
                $field => 'That '.$field.' does not match the one you verified. Please verify it again.',
            ]);
        }

        return $record;
    }

    /**
     * Phone numbers are stored and compared in E.164 so the same number typed
     * two ways is one destination. Emails are lowercased for the same reason.
     */
    public function normalise(string $channel, string $value): string
    {
        $value = trim($value);

        if ($channel === SignupOtp::CHANNEL_EMAIL) {
            return Str::lower($value);
        }

        if (Str::startsWith($value, '+')) {
            return '+'.preg_replace('/\D/', '', $value);
        }

        $digits = preg_replace('/\D/', '', $value);

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10) {
            return config('signup.default_dial_code').$digits;
        }

        return '+'.$digits;
    }

    /**
     * Resending is the button a frustrated user leans on, and each press costs
     * a message. The cooldown is measured from the last code actually issued
     * for this address, not per session, so opening a new session does not
     * reset it.
     */
    private function guardCooldown(string $channel, string $destination): void
    {
        $cooldown = (int) config('signup.resend_cooldown_seconds', 30);

        if ($cooldown <= 0) {
            return;
        }

        $last = SignupOtp::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->latest('created_at')
            ->first();

        if (! $last) {
            return;
        }

        $readyAt = $last->created_at->copy()->addSeconds($cooldown);

        if ($readyAt->isFuture()) {
            /*
            | now() first, then ceil to a whole number. Carbon 3 returns a
            | signed float here, so the operands the other way round produced
            | "please wait -29.685322 seconds".
            */
            $wait = (int) ceil(now()->diffInSeconds($readyAt));

            throw ValidationException::withMessages([
                'destination' => 'Please wait '.max($wait, 1).' second'.($wait === 1 ? '' : 's').' before requesting another code.',
            ]);
        }
    }

    private function sendEmail(string $email, string $otp, int $minutes): bool
    {
        try {
            Mail::to($email)->send(new SignupOtpMail($otp, $minutes));

            return true;
        } catch (\Throwable $e) {
            Log::error('Signup OTP email failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendSms(string $phone, string $otp, int $minutes): bool
    {
        $body = 'Your DollarTraq verification code is '.$otp.'. It expires in '.$minutes.' minutes.';

        if (! $this->sms->isConfigured()) {
            Log::error('Telnyx is not configured; cannot send signup OTP.');

            if (app()->environment('local')) {
                Log::info('=========== Signup OTP ===========');
                Log::info('To', ['phone' => $this->mask($phone)]);
                Log::info('Code', ['otp' => $otp]);
                Log::info('==================================');

                return true;
            }

            return false;
        }

        return $this->sms->send($phone, $body, 'signup OTP');
    }

    private function mask(string $phone): string
    {
        return SmsSender::mask($phone);
    }
}
