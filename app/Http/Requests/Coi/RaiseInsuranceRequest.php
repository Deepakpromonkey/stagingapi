<?php

namespace App\Http\Requests\Coi;

use App\Models\CoiInsuranceRequest;
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

            /*
             | What to ask. Omitted means ask everything, which is what the
             | card does by default — a broker who has not thought about it is
             | better served by a complete answer than by a short mail they
             | have to send twice.
             */
            'asks' => ['nullable', 'array'],
            'asks.*' => ['string', 'in:'.implode(',', array_keys(CoiInsuranceRequest::ASKS))],

            // Only meaningful alongside the `holder` ask.
            'holder_name' => ['nullable', 'string', 'max:255'],

            // A line of the broker's own, for anything the fixed list does not
            // cover — a load-specific question, a reference the agency wants.
            'ask_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
