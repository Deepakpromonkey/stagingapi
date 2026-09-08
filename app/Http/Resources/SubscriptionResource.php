<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'plan' => $this->plan,
            'plan_name' => $this->planName(),
            'status' => $this->status,

            // Whether the account should be let into the product. past_due
            // still counts while Stripe retries the card.
            'is_active' => $this->grantsAccess(),
            'is_paid' => $this->isActive(),
            'is_past_due' => $this->status === Subscription::STATUS_PAST_DUE,

            'amount' => $this->amount(),
            'currency' => $this->currency,
            'interval' => $this->interval,

            // Loads allowed per billing period — 100 on Standard, 250 on Pro,
            // null when the plan is unlimited.
            'load_limit' => $this->loadLimit(),

            'trial_ends_at' => $this->trial_ends_at,
            'current_period_starts_at' => $this->current_period_starts_at,
            'current_period_ends_at' => $this->current_period_ends_at,
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
            'canceled_at' => $this->canceled_at,
        ];
    }
}
