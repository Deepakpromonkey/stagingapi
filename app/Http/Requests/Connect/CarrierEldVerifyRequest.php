<?php

namespace App\Http\Requests\Connect;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The return trip from Terminal Link.
 *
 * `state` is not optional. It is what ties the redirect back to the onboarding
 * request that opened the link — without it, anyone holding a public token
 * could attach a telematics account to someone else's onboarding.
 */
class CarrierEldVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => [
                'required',
                'string',
                'size:64',
            ],

            'public_token' => [
                'required',
                'string',
                'max:500',
            ],

            'state' => [
                'required',
                'string',
                'max:64',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'This onboarding link is not valid.',
            'token.size' => 'This onboarding link is not valid.',
            'public_token.required' => 'The ELD connection did not complete. Please try again.',
        ];
    }
}
