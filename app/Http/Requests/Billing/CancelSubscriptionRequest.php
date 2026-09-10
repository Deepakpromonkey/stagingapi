<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
            | Default false: cancelling should leave the customer the period
            | they have already paid for. Stripe issues no refund for the
            | unused part either way, so ending it early only costs them
            | access.
            */
            'immediately' => ['sometimes', 'boolean'],

            // Stripe's own cancellation feedback values — see
            // config('billing.cancellation.reasons').
            'reason' => [
                'nullable',
                'string',
                Rule::in(array_keys(config('billing.cancellation.reasons'))),
            ],

            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.in' => 'Choose one of the listed reasons.',
            'comment.max' => 'Please keep your note to 500 characters or fewer.',
        ];
    }
}
