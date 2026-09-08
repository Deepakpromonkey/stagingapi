<?php

namespace App\Http\Requests\EmailTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'type' => ['nullable', 'string', Rule::in(array_keys(config('email_templates.types')))],

            'subject' => ['required', 'string', 'max:255'],

            // Rich text from the editor.
            'body_html' => ['required', 'string', 'max:200000'],

            'is_active' => ['nullable', 'boolean'],

            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Unknown template type. Call GET /email-templates/variables for the supported list.',
        ];
    }
}
