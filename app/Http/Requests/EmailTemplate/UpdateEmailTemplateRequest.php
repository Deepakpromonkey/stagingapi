<?php

namespace App\Http\Requests\EmailTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'type' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('email_templates.types')))],

            'subject' => ['sometimes', 'required', 'string', 'max:255'],

            'body_html' => ['sometimes', 'required', 'string', 'max:200000'],

            'is_active' => ['sometimes', 'boolean'],

            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
