<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
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

    public function view(User $user, Company $company): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Company $company): bool
    {
        return false;
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    public function restore(User $user, Company $company): bool
    {
        return false;
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return false;
    }

    public function changeStatus(User $user, Company $company): bool
    {
        return false;
    }

    public function manageMembers(User $user, Company $company): bool
    {
        return false;
    }
}
