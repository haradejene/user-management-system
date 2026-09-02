<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationAccessService
{
    public function applications(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->applications()
            ->orderBy('name')
            ->orderBy('applications.id')
            ->paginate($perPage);
    }

    public function users(Application $application, int $perPage): LengthAwarePaginator
    {
        return $application->users()
            ->orderBy('name')
            ->orderBy('users.id')
            ->paginate($perPage);
    }

    public function grant(User $user, Application $application, User $administrator): Application
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw ValidationException::withMessages([
                'application_id' => 'Access cannot be granted to an inactive application.',
            ]);
        }

        if ($user->applications()->whereKey($application->getKey())->exists()) {
            throw ValidationException::withMessages([
                'application_id' => 'The user already has access to this application.',
            ]);
        }

        return DB::transaction(function () use ($user, $application, $administrator): Application {
            $user->applications()->attach($application->getKey(), [
                'status' => MembershipStatus::Active->value,
                'granted_by' => $administrator->getKey(),
            ]);

            return $user->applications()->whereKey($application->getKey())->firstOrFail();
        });
    }

    public function revoke(User $user, Application $application): void
    {
        DB::transaction(fn (): int => $user->applications()->detach($application->getKey()));
    }

    public function isAllowed(User $user, Application $application): bool
    {
        return $user->hasAccessToApplication($application);
    }
}
