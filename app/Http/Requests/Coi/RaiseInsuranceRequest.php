<?php

namespace App\Http\Requests\Coi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The carrier the broker is asking about.
 *
 * Only the DOT is required. The name and the MC are accepted so the card can
 * pass what it already has on screen, but they are a fallback — the service
 * prefers the carrier database's own answer for anything that goes into a mail
 * addressed to a third party.
 */
class RaiseInsuranceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dot_number' => ['required', 'integer', 'min:1'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_mc' => ['nullable', 'string', 'max:64'],
        ];
    }
}
