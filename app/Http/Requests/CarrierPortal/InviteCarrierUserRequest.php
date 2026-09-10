<?php

namespace App\Http\Requests\CarrierPortal;

use App\Services\Carrier\CarrierRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteCarrierUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],

            'last_name' => ['nullable', 'string', 'max:100'],

            'phone' => ['nullable', 'string', 'max:30'],

            'email' => [
                'required',
                'email',
                'max:255',

                // A carrier login is one per person across the whole platform,
                // so this has to be free everywhere, not just in this account.
                Rule::unique('carrier_users', 'email')->whereNull('deleted_at'),
            ],

            'role_id' => [
                // Stop at the first failure: without this a broker role id
                // trips both rules and reports two errors for one mistake.
                'bail',

                'required',

                // Carrier guard only — a broker seat is not assignable here.
                Rule::exists('roles', 'id')
                    ->where('is_active', true)
                    ->where('guard_name', config('carrier_rbac.guard')),

                function (string $attribute, mixed $value, callable $fail) {
                    $assignable = app(CarrierRoleService::class)
                        ->assignableBy($this->user());

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
            'email.unique' => 'This email already has a carrier portal login.',

            // The usual cause is a broker role id: the two sets live in one
            // table and only the carrier ones can be seated here.
            'role_id.exists' => 'Selected role is not a carrier role. Choose one from the carrier roles list.',
        ];
    }
}
