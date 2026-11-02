<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Developers\StoreOAuthApplicationRequestRequest;
use App\Models\Company;
use App\Models\OAuthApplicationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PublicOAuthRequestController extends Controller
{
    /**
     * List active companies for developers requesting an OAuth application.
     */
    public function companies(): JsonResponse
    {
        $companies = Company::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['public_id', 'name'])
            ->map(fn (Company $c) => [
                'id' => $c->public_id,
                'name' => $c->name,
            ]);

        return response()->json(['data' => $companies]);
    }

    public function store(StoreOAuthApplicationRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $companyId = null;
        $organization = $validated['organization'] ?? null;

        if (! empty($validated['company_id'])) {
            $company = Company::query()->where('public_id', $validated['company_id'])->first();
            if ($company) {
                $companyId = $company->id;
                $organization = $company->name;
            }
        }

        $appRequest = OAuthApplicationRequest::create([
            'name' => $validated['name'],
            'developer_name' => $validated['developer_name'],
            'developer_email' => $validated['developer_email'],
            'company_id' => $companyId,
            'organization' => $organization,
            'description' => $validated['description'] ?? null,
            'redirect_uris' => array_values(array_unique($validated['redirect_uris'])),
            'confidential' => (bool) ($validated['confidential'] ?? true),
            'requested_scopes' => $validated['requested_scopes'] ?? ['openid', 'profile', 'email'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'OAuth application request submitted successfully.',
            'data' => [
                'reference_code' => $appRequest->reference_code,
                'public_id' => $appRequest->public_id,
                'name' => $appRequest->name,
                'developer_name' => $appRequest->developer_name,
                'developer_email' => $appRequest->developer_email,
                'organization' => $appRequest->organization,
                'status' => $appRequest->status,
                'created_at' => $appRequest->created_at->toISOString(),
            ],
        ], Response::HTTP_CREATED);
    }

    public function show(string $reference): JsonResponse
    {
        $normalized = trim($reference);

        $query = OAuthApplicationRequest::query();
        if (Str::isUuid($normalized)) {
            $query->where('public_id', $normalized);
        } else {
            $query->whereRaw('LOWER(reference_code) = ?', [strtolower($normalized)]);
        }

        $appRequest = $query->first();

        if (! $appRequest) {
            return response()->json([
                'message' => 'No OAuth application request found for reference: '.$reference,
            ], Response::HTTP_NOT_FOUND);
        }

        $issuer = config('oidc.issuer', env('APP_URL', 'https://localhost:8443'));

        $data = [
            'reference_code' => $appRequest->reference_code,
            'public_id' => $appRequest->public_id,
            'name' => $appRequest->name,
            'developer_name' => $appRequest->developer_name,
            'developer_email' => $appRequest->developer_email,
            'organization' => $appRequest->organization,
            'description' => $appRequest->description,
            'redirect_uris' => $appRequest->redirect_uris,
            'confidential' => $appRequest->confidential,
            'requested_scopes' => $appRequest->requested_scopes,
            'status' => $appRequest->status,
            'admin_notes' => $appRequest->admin_notes,
            'created_at' => $appRequest->created_at?->toISOString(),
            'reviewed_at' => $appRequest->reviewed_at?->toISOString(),
        ];

        if ($appRequest->status === 'approved') {
            $data['client_id'] = $appRequest->oauth_client_id;
            $data['client_secret'] = $appRequest->client_secret;
            $data['issuer'] = $issuer;
            $data['endpoints'] = [
                'discovery' => url('/.well-known/openid-configuration'),
                'jwks' => url('/oauth/jwks'),
                'authorization' => url('/oauth/authorize'),
                'token' => url('/oauth/token'),
                'userinfo' => url('/oauth/userinfo'),
                'application_access' => url('/oauth/application-access'),
            ];
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}

