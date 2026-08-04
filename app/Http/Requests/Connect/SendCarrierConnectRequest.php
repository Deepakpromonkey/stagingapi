<?php

namespace App\Http\Requests\Connect;

use Illuminate\Foundation\Http\FormRequest;

class SendCarrierConnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'row_id' => [
                'required',
                'string',
                'max:50',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'row_id.required' => 'Select a carrier to connect with.',
        ];
    }
}
