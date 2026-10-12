<?php

namespace App\Pay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A broker's request for DollarTraq to refund a held payment. */
class RefundRequest extends Model
{
    protected $table = 'dtpay_refund_requests';

    public const PENDING = 'pending';
    public const APPROVING = 'approving';   // approved, Stripe refund in flight
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    /** Statuses that still block the payment from being released. */
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
