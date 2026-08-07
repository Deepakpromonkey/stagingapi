<?php

namespace App\Http\Requests\Carrier;

use App\Models\CarrierReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarrierReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'row_id' => ['required', 'string', 'max:50'],

            // An incident cannot have happened tomorrow.
            'incident_date' => ['required', 'date', 'before_or_equal:today'],

            'origin_city' => ['required', 'string', 'max:120'],
            'origin_state' => ['required', 'string', 'max:100'],
            'origin_country' => ['required', 'string', 'max:100'],

            'destination_city' => ['required', 'string', 'max:120'],
            'destination_state' => ['required', 'string', 'max:100'],
            'destination_country' => ['required', 'string', 'max:100'],

            'incidents' => ['required', 'array', 'min:1'],
            'incidents.*' => [Rule::in(array_keys(CarrierReport::INCIDENTS))],

            'comments' => ['nullable', 'string', 'max:5000'],

            'is_private' => ['sometimes', 'boolean'],

            'carrier_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'row_id.required' => 'Select a carrier to report.',
            'incident_date.before_or_equal' => 'The incident date cannot be in the future.',
            'incidents.required' => 'Check at least one incident.',
            'incidents.min' => 'Check at least one incident.',
            'incidents.*.in' => 'One of the selected incidents is not recognised.',
        ];
    }
}
