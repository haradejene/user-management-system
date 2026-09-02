<?php

namespace App\Http\Controllers\Companies;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\AddCompanyMemberRequest;
use App\Http\Requests\Companies\ListMembershipsRequest;
use App\Http\Resources\CompanyMemberResource;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CompanyMembershipController extends Controller
{
    public function index(
        ListMembershipsRequest $request,
        Company $company,
        CompanyMembershipService $memberships,
    ): AnonymousResourceCollection {
        return CompanyMemberResource::collection(
            $memberships->members($company, (int) ($request->validated('per_page') ?? 15))
        );
    }

    public function store(
        AddCompanyMemberRequest $request,
        Company $company,
        CompanyMembershipService $memberships,
    ): JsonResponse {
        $user = User::query()->where('public_id', $request->validated('user_id'))->firstOrFail();

        return (new CompanyMemberResource($memberships->add($company, $user)))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function destroy(Company $company, User $user, CompanyMembershipService $memberships): Response
    {
        $this->authorize('manageMembers', $company);
        $memberships->remove($company, $user);

        return response()->noContent();
    }

    public function forUser(
        ListMembershipsRequest $request,
        User $user,
        CompanyMembershipService $memberships,
    ): AnonymousResourceCollection {
        return CompanyResource::collection(
            $memberships->companies($user, (int) ($request->validated('per_page') ?? 15))
        );
    }
}
