<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'company_name',
        'company_email',
        'company_phone',
        'website',
        'logo',
        'industry',
        'business_type',
        'dot_number',
        'address',
        'city',
        'state',
        'country',
        'zip_code',
        'status',

        // The Terminal consent template this broker's carriers are shown when
        // they link an ELD, so the Link page names the same broker the
        // onboarding wizard does. See docs/eld-terminal.md.
        'eld_consent_template',

        'stripe_customer_id',
        'created_by',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Every Stripe invoice raised against this company, newest first.
     * Mirrored locally — see App\Services\BillingService.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->latest('issued_at');
    }

    /**
     * The subscription the company is billed on right now. Newest wins, so a
     * plan change that leaves the old row behind still resolves correctly.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->grantingAccess()
            ->latestOfMany();
    }

    public function hasActiveSubscription(): bool
    {
        return (bool) $this->subscription?->grantsAccess();
    }

    public function businessTypeLabel(): ?string
    {
        if (! $this->business_type) {
            return null;
        }

        return config('subscriptions.business_types.' . $this->business_type, $this->business_type);
    }
}