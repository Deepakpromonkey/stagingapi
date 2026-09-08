<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifySignupOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp_session' => ['required', 'uuid'],

            // Digits only, exactly six. Anything else cannot be a code this
            // service issued, so it is rejected without costing an attempt.
            'otp' => ['required', 'string', 'digits:6'],
        ];
    }

    /** Users paste codes out of a message; the whitespace comes along. */
    protected function prepareForValidation(): void
    {
        if ($this->has('otp')) {
            $this->merge([
                'otp' => preg_replace('/\s+/', '', (string) $this->input('otp')),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'Enter the 6-digit code.',
            'otp.digits' => 'The code is 6 digits.',
            'otp_session.required' => 'Request a code before verifying.',
        ];
    }
}
