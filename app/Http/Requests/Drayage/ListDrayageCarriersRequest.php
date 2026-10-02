<?php

namespace App\Http\Requests\Drayage;

use App\Services\Drayage\DrayageFields;
use App\Services\Drayage\DrayageQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /drayage/carriers - and, through ExportDrayageCarriersRequest, the
 * export's filters.
 *
 * Every parameter is checked, and one that is not part of the grammar is a
 * 422 rather than silently ignored: a misspelled filter that quietly does
 * nothing returns the whole directory and looks like a working search.
 */
class ListDrayageCarriersRequest extends FormRequest
{
    /** include= values this endpoint accepts. */
    public const INCLUDES = ['trust_score', 'onboarding'];

    /** Multi-value keys holding one value per carrier, where mode=all means nothing. */
    private const SCALAR_MULTI = ['hq_state', 'hq_country', 'record_type'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `hazmat=yes` and `metros=ATL` arrive as strings, `metros[]=...` as
     * arrays; both are treated as lists from here on.
     */
    protected function prepareForValidation(): void
    {
        $wrap = [];

        foreach ([...DrayageFields::MULTI_VALUE, 'has'] as $key) {
            if (is_string($this->query($key))) {
                $wrap[$key] = [$this->query($key)];
            }
        }

        $this->merge($wrap);
    }

    public function rules(): array
    {
        $max = config('drayage.pagination.max_per_page');

        $rules = [
            'q' => ['nullable', 'string', 'max:200'],
            'record_type' => ['nullable', 'array'],
            'record_type.*' => ['string', 'in:full,listing,all'],
            'has' => ['nullable', 'array', 'max:'.count(DrayageFields::PRESENCE)],
            'has.*' => ['string', 'in:'.implode(',', DrayageFields::PRESENCE)],
            'updated_within_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'sort' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$max],
            'fields' => ['nullable', 'string', 'max:2000'],
            'facets' => ['nullable', 'string', 'in:true,false,1,0'],
            'include' => ['nullable', 'string', 'max:100'],
        ];

        foreach (DrayageFields::booleans() as $key) {
            $rules[$key] = ['nullable', 'string', 'in:yes,no,unknown'];
        }

        foreach (DrayageFields::ranges() as $key) {
            $rules[$key.'_min'] = ['nullable', 'numeric', 'min:0'];
            $rules[$key.'_max'] = ['nullable', 'numeric', 'min:0'];
        }

        foreach (DrayageFields::MULTI_VALUE as $key) {
            if ($key === 'record_type') {
                continue;
            }

            $rules[$key] = ['nullable', 'array', 'max:100'];
            $rules[$key.'.*'] = ['string', 'max:120'];
        }

        foreach (DrayageFields::MULTI_VALUE as $key) {
            $rules[$key.'_mode'] = ['nullable', 'string', in_array($key, self::SCALAR_MULTI, true) ? 'in:any' : 'in:any,all'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [];

        foreach (self::SCALAR_MULTI as $key) {
            $messages[$key.'_mode.in'] = "{$key} holds one value per carrier, so only any applies; mode=all is for list fields.";
        }

        return $messages;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->rejectUnknownParameters($validator);
            $this->checkList($validator, 'sort', DrayageFields::sortable(), allowDescending: true);
            $this->checkList($validator, 'fields', $this->selectableFields());
            $this->checkList($validator, 'include', static::INCLUDES);

            foreach (DrayageFields::ranges() as $key) {
                $min = $this->query($key.'_min');
                $max = $this->query($key.'_max');

                if (is_numeric($min) && is_numeric($max) && (float) $min > (float) $max) {
                    $validator->errors()->add($key.'_max', "{$key}_max must be at least {$key}_min.");
                }
            }
        });
    }

    public function drayageQuery(): DrayageQuery
    {
        return DrayageQuery::fromInput($this->validated());
    }

    /** @return list<string> */
    public function includes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->query('include')))));
    }

    /**
     * Every query parameter this endpoint understands.
     */
    protected function allowedParameters(): array
    {
        return array_keys($this->rules());
    }

    protected function selectableFields(): array
    {
        return array_merge(['carrier_key', 'summary'], DrayageFields::keys());
    }

    private function rejectUnknownParameters(Validator $validator): void
    {
        $allowed = array_flip(array_filter($this->allowedParameters(), fn ($k) => ! str_contains($k, '.*')));

        foreach (array_keys($this->query()) as $key) {
            if (! isset($allowed[$key])) {
                $validator->errors()->add($key, "Unknown parameter {$key}. GET /drayage/fields lists the filters this endpoint accepts.");
            }
        }
    }

    private function checkList(Validator $validator, string $parameter, array $allowed, bool $allowDescending = false): void
    {
        $value = $this->query($parameter);

        if (! is_string($value) || trim($value) === '') {
            return;
        }

        foreach (array_map('trim', explode(',', $value)) as $term) {
            $name = $allowDescending ? ltrim($term, '-') : $term;

            if (! in_array($name, $allowed, true)) {
                $validator->errors()->add($parameter, "{$parameter} cannot use \"{$term}\".");
            }
        }
    }
}
