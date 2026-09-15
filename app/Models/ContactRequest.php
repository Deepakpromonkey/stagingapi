<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone_country_code', 'phone',
        'job_title', 'company', 'country', 'business_type',
        'features_of_interest', 'hear_about_us', 'message', 'subscribe_updates'
    ];

    protected $casts = [
        'features_of_interest' => 'array',
        'subscribe_updates' => 'boolean',
    ];
}