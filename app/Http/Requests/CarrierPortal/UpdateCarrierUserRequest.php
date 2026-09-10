<?php

namespace App\Http\Requests\CarrierPortal;

use App\Services\Carrier\CarrierRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCarrierUserRequest extends FormRequest
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

            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],

            // False switches the person off and drops their session.
            'status' => ['sometimes', 'boolean'],

            'role_id' => [
                // One error per mistake — see InviteCarrierUserRequest.
                'bail',

                'sometimes',
                'required',

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
}
