<?php

namespace App\Policies;

use App\Enums\AccountStatus;
use App\Models\User;

class UserPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->status !== AccountStatus::Active) {
            return false;
        }

        return $user->isCentralIamAdministrator() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, User $subject): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $subject): bool
    {
        return false;
    }

    public function delete(User $user, User $subject): bool
    {
        return false;
    }

    public function restore(User $user, User $subject): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $subject): bool
    {
        return false;
    }

    public function changeStatus(User $user, User $subject): bool
    {
        return false;
    }
}
