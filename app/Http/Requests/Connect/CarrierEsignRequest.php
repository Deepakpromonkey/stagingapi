<?php

namespace App\Http\Requests\Connect;

class CarrierEsignRequest extends CarrierConnectTokenRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'signature' => [
                'required',
                'image',
                'mimes:png,jpg,jpeg',
                'max:2048',
            ],

            'page' => [
                'required',
                'integer',
                'min:1',
            ],

            // Position on the page, as a percentage, so the placement survives
            // whatever zoom the carrier signed at.
            'x_pct' => [
                'required',
                'numeric',
                'between:0,100',
            ],

            'y_pct' => [
                'required',
                'numeric',
                'between:0,100',
            ],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'signature.required' => 'Please draw or upload your signature.',
            'signature.image' => 'The signature must be a PNG or JPG image.',
            'signature.max' => 'The signature image may not be larger than 2MB.',
        ]);
    }
}
