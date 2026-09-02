<?php

namespace App\Http\Controllers\Users;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;

class UserStatusController extends Controller
{
    public function deactivate(Request $request, User $user, UserService $users): UserResource
    {
        return $this->changeStatus($request, $user, $users, AccountStatus::Inactive);
    }

    public function suspend(Request $request, User $user, UserService $users): UserResource
    {
        return $this->changeStatus($request, $user, $users, AccountStatus::Suspended);
    }

    public function reactivate(Request $request, User $user, UserService $users): UserResource
    {
        return $this->changeStatus($request, $user, $users, AccountStatus::Active);
    }

    private function changeStatus(
        Request $request,
        User $user,
        UserService $users,
        AccountStatus $status,
    ): UserResource {
        $this->authorize('changeStatus', $user);

        return new UserResource($users->changeStatus($user, $status, $request->user()));
    }
}
