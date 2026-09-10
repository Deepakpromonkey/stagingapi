<?php

namespace App\Http\Requests\Connect;

class CarrierFactoringRequest extends CarrierConnectTokenRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'uses_factoring_company' => [
                'required',
                'boolean',
            ],

            'factoring_company_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            // Mandatory the moment the carrier says yes — the notice of
            // assignment decides who the broker is legally allowed to pay, so a
            // yes without the document is not a usable answer.
            'document' => [
                'required_if_accepted:uses_factoring_company',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'uses_factoring_company.required' => 'Please tell us whether you use a factoring company.',
            'document.required_if_accepted' => 'Upload your factoring notice of assignment to continue.',
            'document.mimes' => 'The factoring document must be a PDF.',
            'document.max' => 'The factoring document may not be larger than 10MB.',
        ]);
    }
}
