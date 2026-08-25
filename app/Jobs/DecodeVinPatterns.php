<?php

namespace App\Jobs;

use App\Services\Vin\VinDecoderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Decode one batch of VIN patterns against NHTSA vPIC.
 *
 * This is the only thing in the application that calls the decoder. Nothing on
 * the request path waits for it: a pattern that is not in the cache yet simply
 * renders blank until this job fills it in.
 */
class DecodeVinPatterns implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /*
     * Deliberately high. WithoutOverlapping releases the job back to the queue
     * when another worker holds the lock, and a release counts as an attempt —
     * with tries = 3 a second worker would burn a batch's attempts on lock
     * contention alone and fail it without ever calling NHTSA. Real failures
     * are bounded by maxExceptions instead.
     */
    public int $tries = 25;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    /**
     * Wait longer between each retry — a batch usually fails because NHTSA is
     * briefly unavailable, and hammering it does not help.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * @param  array<int, string>  $patterns
     */
    public function __construct(public array $patterns)
    {
        // Pinned rather than inherited: on the sync connection this would call
        // NHTSA inline from whatever dispatched it. See config/vin.php.
        $this->onConnection(config('vin.connection', 'database'));
        $this->onQueue(config('vin.queue', 'vin'));
    }

    public function handle(VinDecoderService $decoder): void
    {
        $decoder->decode($this->patterns);
    }

    /**
     * Two workers on the vin queue would otherwise decode against NHTSA in
     * parallel and defeat the pacing set in config/vin.php.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('vpic-decode'))->expireAfter(180)->releaseAfter(30)];
    }

    /**
     * The batch is the unit of identity — re-queuing the same patterns while
     * one is already in flight is wasted work, and two carriers sharing a
     * common truck model will otherwise queue the same pattern twice.
     */
    public function uniqueId(): string
    {
        $patterns = $this->patterns;
        sort($patterns);

        return md5(implode(',', $patterns));
    }

    public int $uniqueFor = 900;
}
