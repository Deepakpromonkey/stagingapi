<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Foundation\Http\FormRequest;

class SearchCarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Filters — independent, so they can be combined.
            'dot_number' => ['nullable', 'string', 'max:20'],
            'mc_number' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],

            // Free text across all of the above.
            'q' => ['nullable', 'string', 'max:255'],

            'sort' => ['nullable', 'string', 'in:name_asc,name_desc'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
