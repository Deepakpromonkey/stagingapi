<?php

namespace App\Jobs;

use App\Exceptions\DrayageException;
use App\Services\Drayage\DrayageImporter;
use App\Services\Drayage\DrayageStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one queued drayage import - the queue plumbing around
 * DrayageImporter::run(), which does the work and writes the report.
 *
 * Pinned to the database queue the scheduler's worker drains, like the VIN
 * and ELD jobs: QUEUE_CONNECTION is `sync` on the API boxes, and inheriting
 * it would run a whole import inside the upload request.
 *
 * If another import holds the lock this one goes back on the queue and
 * tries again shortly; the lock is what keeps two from building at once.
 */
class ImportDrayageDataset implements ShouldQueue
{
    use Queueable;

    // Each wait for the lock spends one; 20 x 30 s is ten minutes of waiting.
    public int $tries = 20;

    public int $timeout;

    public function __construct(public string $importId)
    {
        $this->timeout = (int) config('drayage.import.timeout');

        $this->onConnection(config('drayage.queue.connection'));
        $this->onQueue(config('drayage.queue.name'));
    }

    public function handle(DrayageImporter $importer): void
    {
        try {
            $importer->run($this->importId);
        } catch (DrayageException $e) {
            if ($e->status !== 409) {
                throw $e;
            }

            $this->release(30);
        }
    }

    /**
     * The worker died or gave up mid-import: say so in the import record,
     * rather than leave it "processing" forever.
     */
    public function failed(\Throwable $e): void
    {
        $storage = app(DrayageStorage::class);
        $import = $storage->import($this->importId);

        if ($import && in_array($import['status'], ['queued', 'processing'], true)) {
            $import['status'] = 'failed';
            $import['finished_at'] = now()->toIso8601String();
            $import['error'] = 'The import job did not finish: '.$e->getMessage();
            $storage->saveImport($import);
        }
    }
}
