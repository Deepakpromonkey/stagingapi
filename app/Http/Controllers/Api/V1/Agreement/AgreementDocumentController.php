<?php

namespace App\Http\Controllers\Api\V1\Agreement;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Agreement\StoreAgreementDocumentRequest;
use App\Http\Requests\Agreement\UpdateAgreementDocumentRequest;
use App\Http\Resources\AgreementDocumentResource;
use App\Models\BrokerAgreementDocument;
use App\Services\AgreementDocumentService;
use Illuminate\Http\Request;

class AgreementDocumentController extends BaseController
{
    public function __construct(
        protected AgreementDocumentService $agreementDocumentService
    ) {}

    /**
     * Company's agreement documents. Filter with ?status=active|inactive.
     */
    public function index(Request $request)
    {
        $status = $request->query('status');

        $documents = $this->agreementDocumentService->list(
            $request->user(),
            $status === null ? null : $status === 'active',
            (int) $request->query('per_page', 15),
        );

        return $this->success([
            'documents' => AgreementDocumentResource::collection($documents),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'last_page' => $documents->lastPage(),
                'has_more_pages' => $documents->hasMorePages(),
            ],
        ], 'Agreement documents retrieved successfully.');
    }

    public function store(StoreAgreementDocumentRequest $request)
    {
        $document = $this->agreementDocumentService->create(
            $request->user(),
            $request->validated(),
            $request->file('document')
        );

        return $this->success(
            new AgreementDocumentResource($document->load('uploader')),
            'Agreement document uploaded successfully.',
            201
        );
    }

    public function show(Request $request, string $uuid)
    {
        $document = $this->find($request, $uuid);

        if (! $document) {
            return $this->error('Agreement document not found.', null, 404);
        }

        return $this->success(
            new AgreementDocumentResource($document),
            'Agreement document retrieved successfully.'
        );
    }

    /**
     * Update the title, description, status, or replace the file itself.
     */
    public function update(UpdateAgreementDocumentRequest $request, string $uuid)
    {
        $document = $this->find($request, $uuid);

        if (! $document) {
            return $this->error('Agreement document not found.', null, 404);
        }

        $document = $this->agreementDocumentService->update(
            $document,
            $request->validated(),
            $request->file('document')
        );

        return $this->success(
            new AgreementDocumentResource($document),
            'Agreement document updated successfully.'
        );
    }

    /**
     * Flip active/inactive without sending the whole record back.
     */
    public function toggleStatus(Request $request, string $uuid)
    {
        $document = $this->find($request, $uuid);

        if (! $document) {
            return $this->error('Agreement document not found.', null, 404);
        }

        $document = $this->agreementDocumentService->update(
            $document,
            ['is_active' => ! $document->is_active]
        );

        return $this->success(
            new AgreementDocumentResource($document),
            $document->is_active
                ? 'Agreement document activated.'
                : 'Agreement document deactivated.'
        );
    }

    public function destroy(Request $request, string $uuid)
    {
        $document = $this->find($request, $uuid);

        if (! $document) {
            return $this->error('Agreement document not found.', null, 404);
        }

        $this->agreementDocumentService->delete($document);

        return $this->success(null, 'Agreement document deleted successfully.');
    }

    /**
     * Always scoped to the caller's company, so a uuid from another company
     * reads as "not found".
     */
    protected function find(Request $request, string $uuid): ?BrokerAgreementDocument
    {
        return BrokerAgreementDocument::forCompany($request->user()->company_id)
            ->with('uploader:id,first_name,last_name')
            ->where('uuid', $uuid)
            ->first();
    }
}
