<?php

namespace App\Http\Requests\CarrierPortal;

use Illuminate\Foundation\Http\FormRequest;

class CarrierResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The single-use token handed back by verify-forgot-password-otp.
            'reset_token' => ['required', 'string', 'size:64'],

            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
