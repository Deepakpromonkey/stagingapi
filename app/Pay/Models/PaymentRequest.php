<?php

namespace App\Pay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payer asking DollarTraq to move held money: release it to the carrier,
 * or refund it. Payers never move it themselves; an admin approves (which
 * runs the transfer or refund) or rejects. One open request per payment.
 */
class PaymentRequest extends Model
{
    protected $table = 'dtpay_requests';

    public const RELEASE = 'release';
    public const REFUND = 'refund';

    public const PENDING = 'pending';
    public const APPROVING = 'approving';   // approved, Stripe call in flight
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    /** Statuses still waiting on DollarTraq. */
    public const OPEN = [self::PENDING, self::APPROVING];

    protected $guarded = ['id'];

    protected $casts = ['decided_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
