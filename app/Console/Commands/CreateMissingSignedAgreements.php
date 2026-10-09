<?php

namespace App\Console\Commands;

use App\Models\CarrierConnectRequest;
use App\Services\Carrier\SignedAgreementService;
use Illuminate\Console\Command;

/**
 * Produce the signed agreement for requests that were signed but never got one.
 *
 * Until qpdf was added, every agreement saved as a modern PDF failed to stamp
 * and only the signature image was kept. The original, the signature and its
 * placement are all on the row, so the signed copy can be made now exactly as
 * it would have been at signing.
 */
class CreateMissingSignedAgreements extends Command
{
    protected $signature = 'connect:create-signed-agreements {--dry-run : List the requests without writing anything}';

    protected $description = 'Create the signed agreement PDF for signed connect requests that have none';

    public function handle(SignedAgreementService $signedAgreements): int
    {
        $pending = CarrierConnectRequest::query()
            ->whereNotNull('signature_path')
            ->whereNotNull('agreement_document_id')
            ->whereNull('signed_agreement_path')
            ->with('agreementDocument')
            ->orderBy('id')
            ->get();

        $this->info($pending->count().' signed request(s) without a signed agreement.');

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $created = 0;

        foreach ($pending as $connectRequest) {
            $signed = $signedAgreements->createFor($connectRequest);

            if (! $signed) {
                $this->warn("  #{$connectRequest->id}: could not be stamped (see the log)");

                continue;
            }

            $connectRequest->forceFill([
                'signed_agreement_disk' => $signed['disk'],
                'signed_agreement_path' => $signed['path'],
            ])->save();

            $created++;
        }

        $this->info("Created {$created} signed agreement(s).");

        return $created === $pending->count() ? self::SUCCESS : self::FAILURE;
    }
}
