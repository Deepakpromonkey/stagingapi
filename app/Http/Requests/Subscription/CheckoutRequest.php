<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => [
                'required',
                'string',
                Rule::in(array_keys(config('subscriptions.plans'))),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'plan.required' => 'Choose a plan to continue.',
            'plan.in' => 'That plan is not available.',
        ];
    }
}
