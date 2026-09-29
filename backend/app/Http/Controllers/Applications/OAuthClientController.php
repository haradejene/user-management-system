<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreOAuthClientRequest;
use App\Http\Resources\OAuthClientResource;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Services\OAuthClientService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OAuthClientController extends Controller
{
    public function store(StoreOAuthClientRequest $request, Application $application, OAuthClientService $clients): JsonResponse
    {
        abort_if($application->trashed(), Response::HTTP_NOT_FOUND);

        $client = $clients->create($application, $request->validated());
        $resource = (new OAuthClientResource($client))->resolve($request);

        return response()->json([
            'data' => $resource,
            ...($client->plainSecret !== null ? ['client_secret' => $client->plainSecret] : []),
        ], Response::HTTP_CREATED);
    }

    public function revoke(Application $application, OAuthClient $client, OAuthClientService $clients): Response
    {
        abort_if($application->trashed(), Response::HTTP_NOT_FOUND);
        $clients->revoke($application, $client);

        return response()->noContent();
    }
}
