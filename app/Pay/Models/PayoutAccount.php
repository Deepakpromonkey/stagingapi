<?php

namespace App\Pay\Models;

use Illuminate\Database\Eloquent\Model;

/** A carrier's Stripe Express account, in one Stripe mode. */
class PayoutAccount extends Model
{
    protected $table = 'dtpay_payout_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'livemode' => 'boolean',
        'details_submitted' => 'boolean',
        'payouts_enabled' => 'boolean',
        'transfers_active' => 'boolean',
    ];

    /** Can money be sent to it? */
    public function ready(): bool
    {
        return $this->transfers_active;
    }
}
