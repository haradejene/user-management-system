<?php

namespace App\Http\Controllers\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\ListCompaniesRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CompanyController extends Controller
{
    public function index(ListCompaniesRequest $request, CompanyService $companies): AnonymousResourceCollection
    {
        return CompanyResource::collection($companies->paginate($request->validated()));
    }

    public function store(StoreCompanyRequest $request, CompanyService $companies): JsonResponse
    {
        return (new CompanyResource($companies->create($request->validated())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Company $company): CompanyResource
    {
        $this->authorize('view', $company);

        return new CompanyResource($company);
    }

    public function update(
        UpdateCompanyRequest $request,
        Company $company,
        CompanyService $companies,
    ): CompanyResource {
        return new CompanyResource($companies->update($company, $request->validated()));
    }
}
