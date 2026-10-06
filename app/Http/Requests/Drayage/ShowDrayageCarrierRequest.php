<?php

namespace App\Http\Requests\Drayage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /drayage/carriers/{carrier_key}?include=fmcsa,trust_score,onboarding
 */
class ShowDrayageCarrierRequest extends FormRequest
{
    public const INCLUDES = ['fmcsa', 'trust_score', 'onboarding'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_keys($this->query()) as $key) {
                if ($key !== 'include') {
                    $validator->errors()->add($key, "Unknown parameter {$key}.");
                }
            }

            foreach ($this->includes() as $include) {
                if (! in_array($include, self::INCLUDES, true)) {
                    $validator->errors()->add('include', "include cannot use \"{$include}\". Use any of: ".implode(', ', self::INCLUDES).'.');
                }
            }
        });
    }

    /** @return list<string> */
    public function includes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->query('include')))));
    }
}
