<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LogoutController extends Controller
{
    public function __invoke(Request $request, AuthService $auth): Response
    {
        $auth->logout($request);

        return response()->noContent();
    }
}
