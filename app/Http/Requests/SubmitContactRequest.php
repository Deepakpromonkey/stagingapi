<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitContactRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Public endpoint
    }

    public function rules()
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'phone_country_code' => 'nullable|string|in:+1,+91,+52',
            'phone' => 'nullable|string|max:20',
            'job_title' => 'nullable|string|max:100',
            'company' => 'nullable|string|max:150',
            'country' => 'nullable|string|in:United States,India,Germany,United Kingdom,Australia,Canada,Other',
            'business_type' => 'nullable|string|in:Broker,Shipper,Carrier,3PL,Other',
            'features_of_interest' => 'nullable|array',
            'features_of_interest.*' => 'string|in:Carrier Vetting,Fraud Detection,Load Tracking,Payments,All of the above',
            'hear_about_us' => 'nullable|string|in:Search Engine,Referral,Event Conference,Social Media,Other',
            'message' => 'nullable|string|max:2000',
            'subscribe_updates' => 'nullable|boolean',
        ];
    }
}