<?php

namespace App\Http\Requests\Connect;

/**
 * Structural validation only.
 *
 * Whether an individual answer is required, and what shape it must take, is
 * defined by the broker company's own questions — so those checks happen in the
 * controller, where the question set is loaded anyway.
 */
class CarrierQuestionnaireRequest extends CarrierConnectTokenRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'answers' => [
                'present',
                'array',
            ],

            'answers.*.question_id' => [
                'required',
                'integer',
            ],

            'answers.*.answer' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'answers.*.document' => [
                'nullable',
                'file',
                'mimes:pdf,png,jpg,jpeg',
                'max:10240',
            ],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'answers.present' => 'No answers were submitted.',
            'answers.*.document.mimes' => 'Uploads must be a PDF or an image.',
            'answers.*.document.max' => 'Uploads may not be larger than 10MB.',
        ]);
    }
}
