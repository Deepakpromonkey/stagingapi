<?php

namespace App\Http\Requests\CarrierPortal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The only write the carrier portal's profile screen accepts. Identity,
 * authority and company fields are not editable there — they come from FMCSA
 * and from the onboarding the broker holds.
 */
class CarrierUpdateProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'profile_image.max' => 'The picture may not be larger than 2 MB.',
            'profile_image.mimes' => 'Use a JPG, PNG or WebP image.',
        ];
    }
}
