<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UpdateProfileRequest;
use App\Http\Resources\UserProfileResource;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function own(Request $request): UserProfileResource
    {
        return new UserProfileResource($request->user()->load('profile'));
    }

    public function updateOwn(UpdateProfileRequest $request, ProfileService $profiles): UserProfileResource
    {
        $user = $request->user();

        return new UserProfileResource($profiles->update($user, $request->validated(), $user));
    }

    public function show(User $user): UserProfileResource
    {
        return new UserProfileResource($user->load('profile'));
    }

    public function update(UpdateProfileRequest $request, User $user, ProfileService $profiles): UserProfileResource
    {
        return new UserProfileResource($profiles->update($user, $request->validated(), $request->user()));
    }
}
