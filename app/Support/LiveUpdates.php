<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends a live-update broadcast once the change behind it is committed.
 *
 * After commit, so a page never refetches before the row it was told about is
 * visible, and nothing is announced for a transaction that rolls back. A
 * broadcast that fails is logged and dropped: the socket is a convenience, the
 * pages still refresh on their own, and a save must never fail over it.
 */
class LiveUpdates
{
    public static function send(object $event): void
    {
        DB::afterCommit(function () use ($event) {
            try {
                broadcast($event);
            } catch (\Throwable $e) {
                Log::warning('Live update broadcast failed', [
                    'event' => $event::class,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
