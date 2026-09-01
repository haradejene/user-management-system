<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthService $auth): UserResource
    {
        return new UserResource($auth->login($request->validated(), $request));
    }
}
