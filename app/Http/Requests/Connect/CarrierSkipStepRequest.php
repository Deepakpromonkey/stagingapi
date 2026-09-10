<?php

namespace App\Http\Requests\Connect;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Skipping an onboarding step.
 *
 * The allowed set is closed deliberately. Phone verification, the broker's
 * questionnaire and the agreement are not skippable, so they must not be
 * reachable by passing a different value here.
 */
class CarrierSkipStepRequest extends FormRequest
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

            'step' => [
                'required',
                'string',
                'in:identity,bank',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'This onboarding link is not valid.',
            'token.size' => 'This onboarding link is not valid.',
            'step.in' => 'That step cannot be skipped.',
        ];
    }
}
