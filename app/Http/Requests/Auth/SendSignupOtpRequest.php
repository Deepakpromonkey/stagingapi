<?php

namespace App\Http\Requests\Auth;

use App\Models\SignupOtp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendSignupOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(SignupOtp::CHANNELS)],
            'destination' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * The destination's shape depends on the channel, which a flat rule set
     * cannot express — and getting it wrong here means an SMS gateway is asked
     * to text an email address.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $channel = $this->input('channel');
            $destination = trim((string) $this->input('destination'));

            if ($channel === SignupOtp::CHANNEL_EMAIL) {
                if (! filter_var($destination, FILTER_VALIDATE_EMAIL)) {
                    $validator->errors()->add('destination', 'Enter a valid email address.');

                    return;
                }

                /*
                | Refused here rather than at signup. Sending a code to an
                | address that already has an account walks someone through
                | verification only to fail on the final button — and it is
                | also a free way to confirm which addresses are registered.
                */
                $exists = \App\Models\User::where('email', \Illuminate\Support\Str::lower($destination))->exists();

                if ($exists) {
                    $validator->errors()->add('destination', 'An account already exists for this email address.');
                }

                return;
            }

            $digits = preg_replace('/\D/', '', $destination);

            if (strlen($digits) < 7 || strlen($digits) > 15) {
                $validator->errors()->add('destination', 'Enter a valid phone number.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'channel.in' => 'Codes can only be sent to an email address or a phone number.',
            'destination.required' => 'Enter the address to send the code to.',
        ];
    }
}
