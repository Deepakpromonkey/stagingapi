<?php

namespace App\Http\Requests\CarrierPortal;

use Illuminate\Foundation\Http\FormRequest;

class CarrierForgetDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_uuid' => ['required', 'string', 'max:100'],
        ];
    }
}
