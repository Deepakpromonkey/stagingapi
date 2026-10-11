<?php

namespace App\Pay\Models;

use Illuminate\Database\Eloquent\Model;

/** A company's Stripe customer, in one Stripe mode. */
class Customer extends Model
{
    protected $table = 'dtpay_customers';

    protected $guarded = ['id'];

    protected $casts = ['livemode' => 'boolean'];
}
