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

            /*
            | No longer nullable. Both contact details are verified by OTP
            | before the form can be submitted, and a number that is optional
            | cannot be required to be proved.
            */
            'phone' => 'required|string|max:20',
            'phone_country_code' => 'nullable|string|max:8',

            'password' => 'required|min:8|confirmed',

            /*
            | Proof that the address and the number were verified, issued by
            | /signup/otp/verify.
            |
            | Checked again in the service against the email and phone actually
            | submitted — the token names a destination, and a browser is free
            | to change the field after earning one.
            */
            'email_verification_token' => ['required', 'string', 'size:64'],
            'phone_verification_token' => ['required', 'string', 'size:64'],

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
            'phone.required' => 'Enter the phone number you verified.',
            'email_verification_token.required' => 'Verify your email address before creating the account.',
            'phone_verification_token.required' => 'Verify your phone number before creating the account.',
            'business_type.required' => 'Tell us what your business does.',
            'business_type.in' => 'Choose one of the listed business types.',
            'dot_number.regex' => 'A DOT number is digits only.',
            'plan.in' => 'That plan is not available.',
        ];
    }
}
