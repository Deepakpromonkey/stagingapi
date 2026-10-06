<?php

namespace App\Http\Requests\Drayage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /drayage/carriers/lookup?usdot= | mc= | scac= - exactly one of them.
 */
class LookupDrayageCarriersRequest extends FormRequest
{
    public const TYPES = ['usdot', 'mc', 'scac'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'usdot' => ['nullable', 'string', 'max:20', 'regex:/^\D{0,6}\d{1,12}$/'],
            'mc' => ['nullable', 'string', 'max:20', 'regex:/^\D{0,6}\d{1,12}$/'],
            'scac' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{2,4}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_keys($this->query()) as $key) {
                if (! in_array($key, self::TYPES, true)) {
                    $validator->errors()->add($key, "Unknown parameter {$key}. Use usdot, mc or scac.");
                }
            }

            $given = array_filter(self::TYPES, fn ($type) => filled($this->query($type)));

            if (count($given) !== 1) {
                $validator->errors()->add('lookup', 'Give exactly one of usdot, mc or scac.');
            }
        });
    }

    /** @return array{0: string, 1: string} [type, value] */
    public function lookup(): array
    {
        foreach (self::TYPES as $type) {
            if (filled($this->query($type))) {
                return [$type, (string) $this->query($type)];
            }
        }

        return ['usdot', ''];
    }
}
