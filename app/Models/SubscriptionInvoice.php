<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Stripe invoice raised against a company's subscription.
 *
 * A mirror, like App\Models\Subscription: every field is written from a
 * payload Stripe gave us, never from our own arithmetic. See
 * App\Services\BillingService::syncInvoiceFromStripe().
 */
class SubscriptionInvoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUS_UNCOLLECTIBLE = 'uncollectible';

    /**
     * Invoices worth showing the customer. A draft is Stripe still assembling
     * the next charge — it carries no obligation and can still change, so it
     * stays out of the history.
     */
    public const VISIBLE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PAID,
        self::STATUS_VOID,
        self::STATUS_UNCOLLECTIBLE,
    ];

    protected $fillable = [
        'uuid',
        'company_id',
        'subscription_id',
        'stripe_invoice_id',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'number',
        'status',
        'plan',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'discount_cents',
        'total_cents',
        'amount_paid_cents',
        'amount_due_cents',
        'collection_method',
        'description',
        'hosted_invoice_url',
        'invoice_pdf_url',
        'period_starts_at',
        'period_ends_at',
        'issued_at',
        'due_at',
        'paid_at',
        'card_brand',
        'card_last4',
        'attempt_count',
        'lines',
        'emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'amount_due_cents' => 'integer',
            'attempt_count' => 'integer',
            'lines' => 'array',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', self::VISIBLE_STATUSES);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Still owed: open, and past the date it was due. */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    public function planName(): ?string
    {
        if (! $this->plan) {
            return null;
        }

        return config('subscriptions.plans.'.$this->plan.'.name')
            ?? ucfirst(str_replace('_', ' ', $this->plan));
    }

    /** The invoice total in whole currency units. */
    public function total(): float
    {
        return round($this->total_cents / 100, 2);
    }

    /**
     * What the customer downloads it as. Stripe's invoice number when there is
     * one, otherwise the opaque Stripe id — either way it is unique and
     * traceable back to the provider's record.
     */
    public function fileName(): string
    {
        $reference = $this->number ?: $this->stripe_invoice_id;

        return 'DollarTraq-Invoice-'.preg_replace('/[^A-Za-z0-9\-_]/', '-', $reference).'.pdf';
    }
}
