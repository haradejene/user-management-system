<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ListUsersRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(ListUsersRequest $request, UserService $users): AnonymousResourceCollection
    {
        return UserResource::collection($users->paginate($request->validated()));
    }

    public function store(StoreUserRequest $request, UserService $users): JsonResponse
    {
        return (new UserResource($users->create($request->validated())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user, UserService $users): UserResource
    {
        return new UserResource($users->update($user, $request->validated()));
    }
}
