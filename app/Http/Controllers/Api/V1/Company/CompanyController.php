<?php

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Services\CompanyService;

class CompanyController extends BaseController
{
    protected CompanyService $companyService;

    public function __construct(CompanyService $companyService)
    {
        $this->companyService = $companyService;
    }

    public function show()
    {
        $company = $this->companyService->getCompany(auth()->user());

        return $this->success(
            new CompanyResource($company),
            'Company fetched successfully.'
        );
    }

    public function update(UpdateCompanyRequest $request)
    {
        $company = $this->companyService->updateCompany(
            auth()->user(),
            $request->validated()
        );

        return $this->success(
            new CompanyResource($company),
            'Company updated successfully.'
        );
    }
}
