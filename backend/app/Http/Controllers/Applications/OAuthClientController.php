<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\ListOAuthClientsRequest;
use App\Http\Requests\Applications\StoreOAuthClientRequest;
use App\Http\Requests\Applications\UpdateOAuthRedirectsRequest;
use App\Http\Resources\OAuthClientResource;
use App\Models\Application;
use App\Services\OAuthClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class OAuthClientController extends Controller
{
    public function index(ListOAuthClientsRequest $request, Application $application, OAuthClientService $clients): AnonymousResourceCollection
    {
        return OAuthClientResource::collection($clients->paginate($application, (int) ($request->validated('per_page') ?? 15)));
    }

    public function show(Application $application, string $client, OAuthClientService $clients): OAuthClientResource
    {
        $this->authorize('view', $application);

        return new OAuthClientResource($clients->find($application, $client));
    }

    public function store(StoreOAuthClientRequest $request, Application $application, OAuthClientService $clients): JsonResponse
    {
        abort_if($application->trashed(), Response::HTTP_NOT_FOUND);

        $client = $clients->create($application, $request->validated());
        $resource = (new OAuthClientResource($client))->resolve($request);

        return response()->json([
            'data' => $resource,
            ...($client->plainSecret !== null ? ['client_secret' => $client->plainSecret] : []),
        ], Response::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    public function revoke(Application $application, string $client, OAuthClientService $clients): Response
    {
        $this->authorize('update', $application);
        abort_if($application->trashed(), Response::HTTP_NOT_FOUND);
        $clients->revoke($application, $clients->find($application, $client));

        return response()->noContent();
    }

    public function updateRedirects(UpdateOAuthRedirectsRequest $request, Application $application, string $client, OAuthClientService $clients): OAuthClientResource
    {
        return new OAuthClientResource($clients->updateRedirects($application, $clients->find($application, $client), $request->validated()));
    }
}
