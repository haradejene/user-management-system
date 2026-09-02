<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyMembershipService
{
    public function members(Company $company, int $perPage): LengthAwarePaginator
    {
        return $company->users()
            ->orderBy('name')
            ->orderBy('users.id')
            ->paginate($perPage);
    }

    public function companies(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->companies()
            ->orderBy('name')
            ->orderBy('companies.id')
            ->paginate($perPage);
    }

    public function add(Company $company, User $user): User
    {
        if ($company->status !== MembershipStatus::Active) {
            throw ValidationException::withMessages([
                'company' => 'Members cannot be added to an inactive company.',
            ]);
        }

        if ($company->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'The user is already a member of this company.',
            ]);
        }

        return DB::transaction(function () use ($company, $user): User {
            $company->users()->attach($user->getKey(), [
                'status' => MembershipStatus::Active->value,
            ]);

            return $company->users()->whereKey($user->getKey())->firstOrFail();
        });
    }

    public function remove(Company $company, User $user): void
    {
        if (! $company->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'The user is not a member of this company.',
            ]);
        }

        DB::transaction(fn (): int => $company->users()->detach($user->getKey()));
    }
}
