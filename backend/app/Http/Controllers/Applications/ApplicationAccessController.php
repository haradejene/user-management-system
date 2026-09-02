<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\GrantApplicationAccessRequest;
use App\Http\Requests\Applications\ListApplicationAccessRequest;
use App\Http\Resources\ApplicationUserAccessResource;
use App\Http\Resources\UserApplicationAccessResource;
use App\Models\Application;
use App\Models\User;
use App\Services\ApplicationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ApplicationAccessController extends Controller
{
    public function forUser(
        ListApplicationAccessRequest $request,
        User $user,
        ApplicationAccessService $access,
    ): AnonymousResourceCollection {
        return UserApplicationAccessResource::collection(
            $access->applications($user, (int) ($request->validated('per_page') ?? 15))
        );
    }

    public function forApplication(
        ListApplicationAccessRequest $request,
        Application $application,
        ApplicationAccessService $access,
    ): AnonymousResourceCollection {
        return ApplicationUserAccessResource::collection(
            $access->users($application, (int) ($request->validated('per_page') ?? 15))
        );
    }

    public function store(
        GrantApplicationAccessRequest $request,
        User $user,
        ApplicationAccessService $access,
    ): JsonResponse {
        $application = Application::query()
            ->where('public_id', $request->validated('application_id'))
            ->firstOrFail();

        return (new UserApplicationAccessResource($access->grant($user, $application, $request->user())))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function destroy(
        User $user,
        Application $application,
        ApplicationAccessService $access,
    ): Response {
        $this->authorize('manageAccess', Application::class);
        $access->revoke($user, $application);

        return response()->noContent();
    }
}
