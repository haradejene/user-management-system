<?php

namespace App\Http\Controllers\Companies;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyService;

class CompanyStatusController extends Controller
{
    public function deactivate(Company $company, CompanyService $companies): CompanyResource
    {
        return $this->changeStatus($company, $companies, MembershipStatus::Inactive);
    }

    public function reactivate(Company $company, CompanyService $companies): CompanyResource
    {
        return $this->changeStatus($company, $companies, MembershipStatus::Active);
    }

    private function changeStatus(
        Company $company,
        CompanyService $companies,
        MembershipStatus $status,
    ): CompanyResource {
        $this->authorize('changeStatus', $company);

        return new CompanyResource($companies->changeStatus($company, $status));
    }
}
