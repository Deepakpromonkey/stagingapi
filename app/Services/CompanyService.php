<?php

namespace App\Services;

use App\Models\Company;

class CompanyService
{
    public function getCompany($user)
    {
        return Company::findOrFail($user->company_id);
    }

    public function updateCompany($user, array $data)
    {
        $company = Company::findOrFail($user->company_id);

        $company->update($data);

        return $company->fresh();
    }
}
