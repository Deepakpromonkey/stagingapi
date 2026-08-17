<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'company_email' => 'nullable|email',
            'company_phone' => 'nullable|string|max:20',
            'website' => 'nullable|url',
            'industry' => 'nullable|string|max:255',

            'business_type' => [
                'nullable',
                'string',
                Rule::in(array_keys(config('subscriptions.business_types'))),
            ],
            'dot_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],

            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
        ];
    }
}
