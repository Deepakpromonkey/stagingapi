<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|min:8|confirmed',

            'company_name' => 'required|string|max:255',

            'business_type' => [
                'required',
                'string',
                Rule::in(array_keys(config('subscriptions.business_types'))),
            ],

            // Always optional. Brokers are asked for it during signup, but a
            // brokerage waiting on its authority genuinely has no number yet,
            // and we would rather have the account than a blocked form.
            'dot_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],

            // The plan chosen on the signup form. Optional: leaving it out
            // creates the account and lets the client show the pricing table
            // afterwards instead, which is the same two-step flow as before.
            'plan' => [
                'nullable',
                'string',
                Rule::in(array_keys(config('subscriptions.plans'))),
            ],
        ];
    }

    /**
     * A blank DOT field arrives as an empty string from the signup form;
     * store it as a real null so "no number" reads the same everywhere.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('dot_number')) {
            $dotNumber = trim((string) $this->input('dot_number'));

            $this->merge([
                'dot_number' => $dotNumber === '' ? null : $dotNumber,
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'business_type.required' => 'Tell us what your business does.',
            'business_type.in' => 'Choose one of the listed business types.',
            'dot_number.regex' => 'A DOT number is digits only.',
            'plan.in' => 'That plan is not available.',
        ];
    }
}
