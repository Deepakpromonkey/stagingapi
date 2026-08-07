<?php

namespace App\Http\Requests\Connect;

use Illuminate\Foundation\Http\FormRequest;

class SendCarrierConnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'row_id' => [
                'required',
                'string',
                'max:50',
            ],

            /*
            | Where the invitation should go. Absent means the FMCSA-registered
            | address, which keeps older clients working.
            |
            | This only records which route the broker picked. The FMCSA address
            | itself is always read from the carrier record, never from the
            | request, so passing "fmcsa" cannot smuggle in an arbitrary address
            | that skips the approval step.
            */
            'email_option' => [
                'nullable',
                'string',
                'in:fmcsa,alternate',
            ],

            'email' => [
                'required_if:email_option,alternate',
                'nullable',
                'email',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'row_id.required' => 'Select a carrier to connect with.',
            'email.required_if' => 'Enter the email address to send the invitation to.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
