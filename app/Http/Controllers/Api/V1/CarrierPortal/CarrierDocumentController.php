<?php

namespace App\Http\Controllers\Api\V1\CarrierPortal;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\Carrier\CarrierPortalDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The carrier's own paperwork, back out of the portal.
 *
 * Read-only: documents are uploaded through the onboarding wizard the broker
 * sent, not from here. Files are streamed through this endpoint rather than
 * handed out as URLs, because they live on the private disk and a signed URL
 * would outlive the request that asked for it.
 */
class CarrierDocumentController extends BaseController
{
    public function __construct(
        protected CarrierPortalDocumentService $carrierPortalDocumentService
    ) {}

    public function index(Request $request)
    {
        return $this->success([
            'documents' => $this->carrierPortalDocumentService->for($request->user()),
        ]);
    }

    public function download(Request $request, string $connectionUuid, string $type)
    {
        $file = $this->carrierPortalDocumentService->resolve(
            $request->user(),
            $connectionUuid,
            $type
        );

        // Not theirs, or nothing of that type on that connection. One message
        // for both, so this cannot be used to probe what other carriers hold.
        if (! $file) {
            return $this->error('That document is not available.', null, 404);
        }

        $disk = Storage::disk($file['disk']);

        if (! $disk->exists($file['path'])) {
            Log::warning('Carrier document row points at a missing file', [
                'carrier_user' => $request->user()->uuid,
                'connection' => $connectionUuid,
                'type' => $type,
                'path' => $file['path'],
            ]);

            return $this->error('That document is no longer available.', null, 404);
        }

        return $disk->download($file['path'], $file['name']);
    }
}
