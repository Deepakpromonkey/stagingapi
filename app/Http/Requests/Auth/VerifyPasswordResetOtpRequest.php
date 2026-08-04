<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordResetOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp_session' => [
                'required',
                'uuid',
            ],

            'otp' => [
                'required',
                'digits:6',
            ],
        ];
    }
}
