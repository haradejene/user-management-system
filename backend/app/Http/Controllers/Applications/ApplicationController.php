<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\ListApplicationsRequest;
use App\Http\Requests\Applications\StoreApplicationRequest;
use App\Http\Requests\Applications\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApplicationController extends Controller
{
    public function index(
        ListApplicationsRequest $request,
        ApplicationService $applications,
    ): AnonymousResourceCollection {
        return ApplicationResource::collection($applications->paginate($request->validated()));
    }

    public function store(StoreApplicationRequest $request, ApplicationService $applications): JsonResponse
    {
        return (new ApplicationResource($applications->create($request->validated())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Application $application): ApplicationResource
    {
        $this->authorize('view', $application);

        return new ApplicationResource($application);
    }

    public function update(
        UpdateApplicationRequest $request,
        Application $application,
        ApplicationService $applications,
    ): ApplicationResource {
        return new ApplicationResource($applications->update($application, $request->validated()));
    }
}
