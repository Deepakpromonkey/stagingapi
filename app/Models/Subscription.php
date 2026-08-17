<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A company's monthly Stripe subscription.
 *
 * Stripe is the source of truth: every field here is a mirror kept in step by
 * App\Services\SubscriptionService, either from a webhook or from an explicit
 * sync when the customer returns from Checkout.
 */
class Subscription extends Model
{
    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_CANCELED = 'canceled';

    /**
     * Statuses that still buy access to the product. past_due is included on
     * purpose — Stripe retries a failed card for days, and locking a broker
     * out mid-load over one declined charge does more damage than it prevents.
     */
    public const ACCESS_STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
    ];

    protected $fillable = [
        'uuid',
        'company_id',
        'plan',
        'status',
        'stripe_subscription_id',
        'stripe_price_id',
        'stripe_checkout_session_id',
        'amount_cents',
        'currency',
        'interval',
        'load_limit',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancel_at_period_end',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'load_limit' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeGrantingAccess(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACCESS_STATUSES);
    }

    public function grantsAccess(): bool
    {
        return in_array($this->status, self::ACCESS_STATUSES, true);
    }

    /** Paid up, as opposed to merely not yet locked out. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_TRIALING, self::STATUS_ACTIVE], true);
    }

    public function planConfig(): array
    {
        return config('subscriptions.plans.'.$this->plan, []);
    }

    public function planName(): string
    {
        return $this->planConfig()['name'] ?? ucfirst(str_replace('_', ' ', $this->plan));
    }

    /**
     * Loads allowed per billing period. The row's own override wins, so a
     * negotiated Enterprise allowance survives a change to the plan config;
     * null means unlimited.
     */
    public function loadLimit(): ?int
    {
        if ($this->load_limit !== null) {
            return $this->load_limit;
        }

        $configured = $this->planConfig()['load_limit'] ?? null;

        return $configured !== null ? (int) $configured : null;
    }

    public function hasUnlimitedLoads(): bool
    {
        return $this->loadLimit() === null;
    }

    /**
     * Start of the window loads are counted over. Stripe reports it once the
     * subscription is live; before then the row's own creation date is the
     * closest honest answer.
     */
    public function periodStartsAt(): Carbon
    {
        return $this->current_period_starts_at
            ?? $this->created_at
            ?? now()->startOfMonth();
    }

    /** The snapshot amount in dollars, falling back to the configured price. */
    public function amount(): ?float
    {
        if ($this->amount_cents !== null) {
            return round($this->amount_cents / 100, 2);
        }

        $configured = $this->planConfig()['amount'] ?? null;

        return $configured !== null ? (float) $configured : null;
    }
}
