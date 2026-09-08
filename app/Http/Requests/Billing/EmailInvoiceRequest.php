<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class EmailInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
            | Optional: with nothing here the invoice goes to the company's
            | billing address. Given an address, it goes there instead — which
            | is how an operator forwards a copy to their bookkeeper without
            | changing the account's billing email.
            */
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
