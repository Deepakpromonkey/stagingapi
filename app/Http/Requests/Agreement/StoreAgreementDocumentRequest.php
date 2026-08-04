<?php

namespace App\Http\Requests\Agreement;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgreementDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],

            'description' => ['nullable', 'string', 'max:2000'],

            'document' => [
                'required',
                'file',
                'mimes:pdf,doc,docx',
                'max:10240', // 10 MB
            ],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.mimes' => 'The agreement must be a PDF or Word document.',
            'document.max' => 'The agreement may not be larger than 10 MB.',
        ];
    }
}
