<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // What the frontend addresses this invoice by — never the numeric
            // id, and not Stripe's id either, so the two systems stay
            // decoupled the same way the rest of the API works.
            'uuid' => $this->uuid,

            'number' => $this->number,
            'status' => $this->status,
            'is_paid' => $this->isPaid(),
            'is_overdue' => $this->isOverdue(),

            'plan' => $this->plan,
            'plan_name' => $this->planName(),

            'currency' => $this->currency,

            // Whole currency units, so the frontend never divides by 100.
            'subtotal' => round($this->subtotal_cents / 100, 2),
            'tax' => round($this->tax_cents / 100, 2),
            'discount' => round($this->discount_cents / 100, 2),
            'total' => $this->total(),
            'amount_paid' => round($this->amount_paid_cents / 100, 2),
            'amount_due' => round($this->amount_due_cents / 100, 2),

            'description' => $this->description,

            'period_starts_at' => $this->period_starts_at,
            'period_ends_at' => $this->period_ends_at,
            'issued_at' => $this->issued_at,
            'due_at' => $this->due_at,
            'paid_at' => $this->paid_at,

            'card_brand' => $this->card_brand,
            'card_last4' => $this->card_last4,

            // Stripe's hosted copy, shown alongside our own PDF so the
            // customer can always reach the provider's record.
            'hosted_invoice_url' => $this->hosted_invoice_url,
            'stripe_pdf_url' => $this->invoice_pdf_url,

            'lines' => collect($this->lines ?: [])->map(fn (array $line) => [
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? 1,
                'amount' => round(($line['amount_cents'] ?? 0) / 100, 2),
                'proration' => (bool) ($line['proration'] ?? false),
                'period_starts_at' => $line['period_starts_at'] ?? null,
                'period_ends_at' => $line['period_ends_at'] ?? null,
            ])->values(),

            'emailed_at' => $this->emailed_at,
        ];
    }
}
