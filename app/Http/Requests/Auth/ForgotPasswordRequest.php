<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Deliberately not validated against the users table: the
            // response must not reveal whether an account exists.
            'email' => [
                'required',
                'email',
                'max:255',
            ],
        ];
    }
}
