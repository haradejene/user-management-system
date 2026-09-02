<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isCentralIamAdministrator()
            ? true
            : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Application $application): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Application $application): bool
    {
        return false;
    }

    public function delete(User $user, Application $application): bool
    {
        return false;
    }

    public function restore(User $user, Application $application): bool
    {
        return false;
    }

    public function forceDelete(User $user, Application $application): bool
    {
        return false;
    }

    public function manageAccess(User $user): bool
    {
        return false;
    }

    public function changeStatus(User $user, Application $application): bool
    {
        return false;
    }
}
