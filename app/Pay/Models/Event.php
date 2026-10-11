<?php

namespace App\Pay\Models;

use Illuminate\Database\Eloquent\Model;

/** One line in a payment's timeline. */
class Event extends Model
{
    protected $table = 'dtpay_events';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array'];
}
