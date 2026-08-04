<?php

namespace App\Http\Requests\CarrierPortal;

use Illuminate\Foundation\Http\FormRequest;

class CarrierVerifyLoginOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp_session' => ['required', 'uuid'],

            'otp' => ['required', 'digits:6'],

            // Defaults to the device the challenge was raised for.
            'device_uuid' => ['nullable', 'string', 'max:100'],

            // "Remember this device" — skips the code for 30 days. Needs a
            // device_uuid to attach to, on this request or the login before it.
            'remember_device' => ['nullable', 'boolean'],
        ];
    }
}
