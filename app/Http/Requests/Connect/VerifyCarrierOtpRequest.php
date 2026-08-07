<?php

namespace App\Http\Requests\Connect;

class VerifyCarrierOtpRequest extends CarrierConnectTokenRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'otp' => [
                'required',
                'digits:6',
            ],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'otp.required' => 'Enter the 6 digit code we sent you.',
            'otp.digits' => 'The code is 6 digits.',
        ]);
    }
}
