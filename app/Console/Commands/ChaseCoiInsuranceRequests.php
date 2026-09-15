<?php

namespace App\Console\Commands;

use App\Models\CoiInsuranceRequest;
use App\Services\Coi\CarrierInsuranceRequestService;
use Illuminate\Console\Command;

/**
 * Asks again, for the requests nobody answered.
 *
 * Sequence 05 is the shape: the first mail goes unread, a second lands the
 * next day, a third names a deadline, and then the chasing stops whether or
 * not a certificate ever arrives. Stopping is the part that matters — a
 * fourth and fifth mail is what gets the sending domain filtered, and the
 * carrier is nudged by other means at that point.
 */
class ChaseCoiInsuranceRequests extends Command
{
    protected $signature = 'coi:chase-requests {--hours= : Override the configured interval}
                                               {--max= : Override the configured cap}';

    protected $description = 'Re-send certificate requests that have had no reply';

    public function handle(CarrierInsuranceRequestService $service): int
    {
        $hours = (int) ($this->option('hours') ?: config('coi_insurance.chase.after_hours', 24));
        $max = (int) ($this->option('max') ?: config('coi_insurance.chase.max', 2));

        $cutoff = now()->subHours($hours);

        /*
         | Only `pending`. A request that has had any reply at all is somebody
         | else's problem now — `awaiting` is waiting on a promise that was
         | made, and chasing it would talk over a conversation in progress.
         */
        $due = CoiInsuranceRequest::where('status', CoiInsuranceRequest::STATUS_PENDING)
            ->where('chase_count', '<', $max)
            ->whereDoesntHave('responses')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_chase_at', '<', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('last_chase_at')->where('sent_at', '<', $cutoff));
            })
            ->get();

        foreach ($due as $request) {
            $request->forceFill([
                'chase_count' => $request->chase_count + 1,
                'last_chase_at' => now(),
            ])->save();

            $service->send($request);

            $this->line('Chased '.$request->dot_number.' ('.$request->chase_count.' of '.$max.')');
        }

        $this->info($due->count().' request(s) chased.');

        return self::SUCCESS;
    }
}
