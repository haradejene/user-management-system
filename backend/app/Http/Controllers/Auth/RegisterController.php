<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, AuthService $auth): JsonResponse
    {
        $user = $auth->register($request->validated(), $request);

        return (new UserResource($user))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
