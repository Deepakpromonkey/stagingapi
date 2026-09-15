<?php

namespace App\Http\Requests\Invitation;

use App\Services\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => [
                'required',
                'string',
                'max:100',
            ],

            'last_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:20',
            ],

            // ISO alpha-2 for the dialling country the invite form picked.
            // Constrained to the four countries the form offers, so a stray
            // value cannot land in the column.
            'country_code' => [
                'nullable',
                'string',
                Rule::in(['US', 'CA', 'MX', 'IN']),
            ],

            'email' => [
                'required',
                'email',
                'max:255',

                // Email should not already exist as a user. Removed teammates
                // keep their row so their history stays intact, so skip those
                // — otherwise the same person could never be invited back.
                Rule::unique('users', 'email')
                    ->whereNull('deleted_at'),

                // Email should not already have a pending invitation.
                Rule::unique('invitations', 'email')
                    ->whereNull('accepted_at'),
            ],

            'role_id' => [
                'required',

                // Broker guard only — carrier seats live in the same table.
                Rule::exists('roles', 'id')
                    ->where('is_active', true)
                    ->where('guard_name', 'web'),

                // A Compliance Manager may only seat Viewer / Agent /
                // Senior Agent; Owner/Admin and Compliance Manager seats are
                // reserved for an Owner/Admin.
                function (string $attribute, mixed $value, callable $fail) {
                    $roleService = app(RoleService::class);

                    $assignable = $roleService->assignableBy($this->user());

                    if (! $assignable->contains('id', (int) $value)) {
                        $fail('You are not allowed to assign this role.');
                    }
                },
            ],

            // Optional per-user override capability, layered on top of the
            // seat. Omit to inherit the role default. You can only grant an
            // override you hold yourself.
            'can_override_soft' => [
                'nullable',
                'boolean',
                function (string $attribute, mixed $value, callable $fail) {
                    if ($this->boolean($attribute) && ! $this->user()->canOverrideSoft()) {
                        $fail('You are not allowed to grant soft-gate override capability.');
                    }
                },
            ],

            'can_override_gate' => [
                'nullable',
                'boolean',
                function (string $attribute, mixed $value, callable $fail) {
                    if ($this->boolean($attribute) && ! $this->user()->canOverrideGate()) {
                        $fail('You are not allowed to grant knockout-gate override capability.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered or has a pending invitation.',
            'role_id.exists' => 'Selected role is invalid.',
        ];
    }
}