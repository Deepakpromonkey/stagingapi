<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreScoringWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'authority'       => ['required', 'integer', 'min:0', 'max:100'],
            'insurance_coi'   => ['required', 'integer', 'min:0', 'max:100'],
            'safety_csa'      => ['required', 'integer', 'min:0', 'max:100'],
            'inspection_vin'  => ['required', 'integer', 'min:0', 'max:100'],
            'fraud_signals'   => ['required', 'integer', 'min:0', 'max:100'],
            'payment_history' => ['required', 'integer', 'min:0', 'max:100'],
            
            // New Template Fields
            'save_as_template' => ['nullable', 'boolean'],
            'template_name'    => ['required_if:save_as_template,true', 'nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $totalSum = $this->authority + 
                            $this->insurance_coi + 
                            $this->safety_csa + 
                            $this->inspection_vin + 
                            $this->fraud_signals + 
                            $this->payment_history;

                if ($totalSum !== 100) {
                    $validator->errors()->add(
                        'total_weight', 
                        "The total scoring weights must equal exactly 100%. Your current total is {$totalSum}%."
                    );
                }
            }
        ];
    }
}