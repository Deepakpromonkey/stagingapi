<?php

namespace App\Http\Requests\Connect;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The carrier is not a logged-in user, so every carrier-facing onboarding call
 * is authorised by the invitation token alone.
 */
class CarrierConnectTokenRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'This onboarding link is not valid.',
            'token.size' => 'This onboarding link is not valid.',
        ];
    }
}
