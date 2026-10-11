<?php

namespace App\Pay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One DT Pay payment: a payer funding a carrier for one load.
 */
class Payment extends Model
{
    protected $table = 'dtpay_payments';

    public const DRAFT = 'draft';
    public const PROCESSING = 'processing';   // ACH clearing, or card needs 3-D Secure
    public const FUNDED = 'funded';           // held by DollarTraq, ready to release
    public const RELEASING = 'releasing';
    public const RELEASED = 'released';
    public const REFUNDING = 'refunding';
    public const REFUNDED = 'refunded';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const SOURCE_SHIPMENT = 'shipment';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_GUEST = 'guest';

    protected $guarded = ['id'];

    protected $casts = [
        'amount_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'card_fee_cents' => 'integer',
        'total_cents' => 'integer',
        'platform_fee_pct' => 'float',
        'livemode' => 'boolean',
        'on_hold' => 'boolean',
        'pickup_date' => 'date:Y-m-d',
        'delivery_date' => 'date:Y-m-d',
        'funded_at' => 'datetime',
        'released_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected $hidden = ['guest_token'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class)->orderBy('id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class)->orderByDesc('id');
    }

    public function openDispute(): ?Dispute
    {
        return $this->disputes()->where('status', Dispute::OPEN)->first();
    }

    /** Can the payer still pay (or pay again after a failure)? */
    public function payable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::FAILED], true);
    }
}
