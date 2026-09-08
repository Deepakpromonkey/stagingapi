<?php

namespace App\Http\Requests\Connect;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finishing the ELD connection the carrier just made at Terminal.
 *
 * Both tokens are required. The public token is single use and is exchanged
 * server-side for the long-lived connection token; the state is the nonce we
 * generated when the Link page was opened, and without it a return URL
 * replayed from a browser history — or forged by someone who guessed an
 * onboarding token — would be enough to attach a connection.
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
                'max:128',
            ],

            'state' => [
                'required',
                'string',
                'max:128',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'This onboarding link is not valid.',
            'token.size' => 'This onboarding link is not valid.',
            'public_token.required' => 'That connection could not be completed. Please try again.',
            'state.required' => 'That connection could not be completed. Please try again.',
        ];
    }
}
