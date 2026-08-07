<?php

namespace App\Services\Carrier;

use App\Models\CarrierConnectDocument;
use App\Models\CarrierConnectRequest;
use App\Models\CarrierUser;
use Illuminate\Database\Eloquent\Collection;

/**
 * The paperwork a carrier can pull back out of the portal.
 *
 * Everything here was handed over during an onboarding, so each file belongs to
 * one broker connection rather than to the carrier in the abstract: a carrier
 * who onboarded with three brokers uploaded three W-9s, and may well have
 * uploaded a newer one to the third. They are listed per connection instead of
 * deduplicated by type, because the carrier's question is usually "what does
 * this broker hold for me", not "what is my latest W-9".
 *
 * Nothing is publicly readable — the files live on the private disk and are
 * streamed back through the API, never linked to directly.
 */
class CarrierPortalDocumentService
{
    /** Non-`carrier_connect_documents` files that still belong to the carrier. */
    public const TYPE_AGREEMENT = 'agreement';

    public const TYPE_NOA = 'noa';

    public const LABELS = [
        self::TYPE_AGREEMENT => 'Signed carrier agreement',
        self::TYPE_NOA => 'Notice of assignment',
    ];

    public function __construct(
        protected CarrierConnectionService $carrierConnectionService
    ) {}

    /**
     * Every downloadable file this carrier has, newest connection first.
     *
     * @return array<int, array>
     */
    public function for(CarrierUser $carrierUser): array
    {
        $documents = [];

        foreach ($this->connections($carrierUser) as $connection) {
            foreach ($this->forConnection($connection) as $document) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * Resolve one file for download, scoped to what this carrier may have.
     *
     * Returns null when the connection is not theirs or holds no file of that
     * type — the caller cannot tell those two apart, which is deliberate.
     *
     * @return array{disk: string, path: string, name: string}|null
     */
    public function resolve(CarrierUser $carrierUser, string $connectionUuid, string $type): ?array
    {
        $connection = $this->connections($carrierUser)
            ->firstWhere('uuid', $connectionUuid);

        if (! $connection) {
            return null;
        }

        if ($type === self::TYPE_AGREEMENT) {
            $agreement = $connection->agreementDocument;

            // Only once they have actually signed it: an unsigned agreement is
            // the broker's document, not the carrier's copy of anything.
            if (! $agreement || $connection->signed_at === null) {
                return null;
            }

            return [
                'disk' => $agreement->disk,
                'path' => $agreement->file_path,
                'name' => $agreement->file_name ?: 'carrier-agreement.pdf',
            ];
        }

        if ($type === self::TYPE_NOA) {
            if (! $connection->factoring_document_path) {
                return null;
            }

            return [
                'disk' => $connection->factoring_document_disk,
                'path' => $connection->factoring_document_path,
                'name' => $connection->factoring_document_name ?: 'notice-of-assignment.pdf',
            ];
        }

        $document = $connection->documents->firstWhere('type', $type);

        if (! $document) {
            return null;
        }

        return [
            'disk' => $document->disk,
            'path' => $document->path,
            'name' => $document->name,
        ];
    }

    /**
     * The carrier's connections with everything this screen needs loaded.
     *
     * @return Collection<int, CarrierConnectRequest>
     */
    protected function connections(CarrierUser $carrierUser): Collection
    {
        return $this->carrierConnectionService
            ->forCarrier($carrierUser)
            ->load('documents', 'agreementDocument');
    }

    /**
     * @return array<int, array>
     */
    protected function forConnection(CarrierConnectRequest $connection): array
    {
        $broker = [
            'uuid' => $connection->company?->uuid,
            'company_name' => $connection->company?->company_name,
        ];

        $documents = [];

        foreach ($connection->documents as $document) {
            $documents[] = [
                'connection_uuid' => $connection->uuid,
                'broker' => $broker,
                'type' => $document->type,
                'label' => $document->label(),
                'name' => $document->name,
                'size' => $document->size,
                'mime' => $document->mime,
                'uploaded_at' => $document->created_at?->toIso8601String(),
            ];
        }

        if ($connection->signed_at && $connection->agreementDocument) {
            $documents[] = [
                'connection_uuid' => $connection->uuid,
                'broker' => $broker,
                'type' => self::TYPE_AGREEMENT,
                'label' => self::LABELS[self::TYPE_AGREEMENT],
                'name' => $connection->agreementDocument->file_name,
                'size' => $connection->agreementDocument->file_size,
                'mime' => $connection->agreementDocument->mime_type,
                'uploaded_at' => $connection->signed_at->toIso8601String(),
            ];
        }

        if ($connection->factoring_document_path) {
            $documents[] = [
                'connection_uuid' => $connection->uuid,
                'broker' => $broker,
                'type' => self::TYPE_NOA,
                'label' => self::LABELS[self::TYPE_NOA],
                'name' => $connection->factoring_document_name,
                'size' => null,
                'mime' => null,
                'uploaded_at' => $connection->updated_at?->toIso8601String(),
            ];
        }

        return $documents;
    }

    /**
     * Types the download route will accept, for route-level validation.
     *
     * @return array<int, string>
     */
    public static function downloadableTypes(): array
    {
        return array_merge(
            array_keys(CarrierConnectDocument::TYPES),
            [self::TYPE_AGREEMENT, self::TYPE_NOA]
        );
    }
}
