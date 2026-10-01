<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Whether the account exists is checked in
            // PasswordResetService::sendOtp, alongside its status.
            'email' => [
                'required',
                'email',
                'max:255',
            ],
        ];
    }
}
