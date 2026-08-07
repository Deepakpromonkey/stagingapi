<?php

namespace App\Http\Requests\Connect;

use App\Models\CarrierConnectDocument;
use Illuminate\Validation\Rule;

class CarrierDocumentRequest extends CarrierConnectTokenRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'type' => [
                'required',
                Rule::in(array_keys(CarrierConnectDocument::TYPES)),
            ],

            // A COI is routinely issued as an image by the agent, so this is
            // deliberately wider than the PDF-only factoring notice.
            'document' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240',
            ],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'type.in' => 'That document type is not recognised.',
            'document.required' => 'Choose a file to upload.',
            'document.mimes' => 'Upload a PDF or an image (JPG or PNG).',
            'document.max' => 'The document may not be larger than 10MB.',
        ]);
    }
}
