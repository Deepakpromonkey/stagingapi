<?php

namespace App\Http\Requests\CarrierPortal;

use Illuminate\Foundation\Http\FormRequest;

class CarrierLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],

            // Stable identifier the portal generates once per browser. Without
            // it every sign-in is treated as a new device and challenged.
            'device_uuid' => ['nullable', 'string', 'max:100'],
        ];
    }
}
