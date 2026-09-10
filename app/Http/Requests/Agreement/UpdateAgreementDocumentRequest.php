<?php

namespace App\Http\Requests\Agreement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgreementDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],

            'description' => ['nullable', 'string', 'max:2000'],

            // Optional replacement file; the old object is removed from S3.
            'document' => ['sometimes', 'file', 'mimes:pdf,doc,docx', 'max:10240'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
