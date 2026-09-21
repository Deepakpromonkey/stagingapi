<?php

namespace App\Console\Commands;

use App\Services\Eld\EldTrackingService;
use Illuminate\Console\Command;

class PollEldShipments extends Command
{
    protected $signature = 'eld:poll-shipments';

    protected $description = 'Fetch the latest ELD position for every load currently in flight.';

    public function handle(EldTrackingService $tracking): int
    {
        $tracking->pollAll();

        return self::SUCCESS;
    }
}