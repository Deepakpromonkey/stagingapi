<?php

namespace App\Http\Requests\User;

use App\Services\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],

            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],

            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],

            // ISO alpha-2 for the dialling country, same four the team
            // form offers.
            'country_code' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['US', 'CA', 'MX', 'IN']),
            ],

            'designation' => ['sometimes', 'nullable', 'string', 'max:100'],

            // False switches the person off and drops their session. The seat
            // is kept, so switching them back on restores their access.
            'status' => ['sometimes', 'boolean'],

            'role_id' => [
                // One error per mistake — without this the closure below also
                // runs on an id that failed `exists`, and the caller gets two
                // messages for a single bad value.
                'bail',

                'sometimes',

                'required',

                // Broker guard only — carrier seats live in the same table.
                Rule::exists('roles', 'id')
                    ->where('is_active', true)
                    ->where('guard_name', 'web'),

                // A Compliance Manager may only seat Viewer / Agent / Senior
                // Agent; Owner/Admin and Compliance Manager seats are reserved
                // for an Owner/Admin. Re-checked in the service too, so the
                // rule holds for callers that skip this request.
                function (string $attribute, mixed $value, callable $fail) {
                    $assignable = app(RoleService::class)->assignableBy($this->user());

                    if (! $assignable->contains('id', (int) $value)) {
                        $fail('You are not allowed to assign this role.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.exists' => 'Selected role is invalid.',
        ];
    }
}