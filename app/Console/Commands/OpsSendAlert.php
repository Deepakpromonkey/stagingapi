<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsAlert;
use Illuminate\Console\Command;

/**
 * Send an alert by hand - to test the channel and the mailbox after setting
 * up a box, or from a shell script that wants the same delivery.
 *
 *   php artisan ops:alert "Test alert" --detail="Checking the wiring"
 */
class OpsSendAlert extends Command
{
    protected $signature = 'ops:alert
        {title : Headline of the alert}
        {--detail= : One line of detail}
        {--level=warning : critical, warning or resolved}';

    protected $description = 'Send an alert to the Teams channel and the alert mailboxes';

    public function handle(OpsAlert $alerts): int
    {
        if (! OpsAlert::enabled()) {
            $this->error('OPS_ROLE is not set in .env, so alerts are off on this box.');

            return self::FAILURE;
        }

        $level = in_array($this->option('level'), [OpsAlert::CRITICAL, OpsAlert::WARNING, OpsAlert::RESOLVED], true)
            ? $this->option('level')
            : OpsAlert::WARNING;

        $sent = $alerts->send(
            $level,
            $this->argument('title'),
            array_filter(['Detail' => $this->option('detail')]),
            'manual|'.$this->argument('title').'|'.microtime(true),
        );

        $sent ? $this->info('Sent.') : $this->error('Not sent - see storage/logs/laravel.log.');

        return $sent ? self::SUCCESS : self::FAILURE;
    }
}
