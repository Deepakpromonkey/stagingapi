<?php

namespace App\Console\Commands;

use App\Models\CoiInsuranceRequest;
use Illuminate\Console\Command;

/**
 * Close out requests nobody answered.
 *
 * Without this the card says "Pending" forever on a mail an agency ignored two
 * months ago, which reads as "we are still waiting" rather than "nobody
 * replied" — and the cooldown in the service would never let the broker ask
 * again, because an open request short-circuits it.
 */
class ExpireCoiInsuranceRequests extends Command
{
    protected $signature = 'coi:expire-requests {--days= : Override the configured window}';

    protected $description = 'Mark unanswered carrier insurance requests as expired';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('coi_insurance.expire_after_days', 14));

        $cutoff = now()->subDays($days);

        /*
         | Only genuinely unanswered rows. A `responded` request is waiting on
         | an extraction, not on the agency, and expiring it would throw away a
         | reply that is already in hand.
         */
        $expired = CoiInsuranceRequest::where('status', CoiInsuranceRequest::STATUS_PENDING)
            ->where(function ($query) use ($cutoff) {
                $query->where('sent_at', '<', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('sent_at')->where('created_at', '<', $cutoff));
            })
            ->update([
                'status' => CoiInsuranceRequest::STATUS_EXPIRED,
                'resolved_at' => now(),
                'last_error' => 'No reply was received within '.$days.' days.',
                'updated_at' => now(),
            ]);

        $this->info($expired.' request(s) expired.');

        return self::SUCCESS;
    }
}
