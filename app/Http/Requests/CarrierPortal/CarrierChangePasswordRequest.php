<?php

namespace App\Http\Requests\CarrierPortal;

use Illuminate\Foundation\Http\FormRequest;

class CarrierChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // On a first sign-in this is the temporary password from the
            // account email.
            'current_password' => ['required', 'string'],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'different:current_password',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'The new password must be different from your current password.',
        ];
    }
}
