<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Events\IamActivityOccurred;
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
        return DB::transaction(function () use ($company, $user): User {
            $company = Company::query()->lockForUpdate()->findOrFail($company->getKey());
            $user = User::withTrashed()->lockForUpdate()->findOrFail($user->getKey());
            if ($company->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages([
                    'company' => 'Members cannot be added to an inactive company.',
                ]);
            }
            if ($user->trashed()) {
                throw ValidationException::withMessages([
                    'user_id' => 'Deleted users cannot be added to a company.',
                ]);
            }
            if ($company->users()->whereKey($user->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => 'The user is already a member of this company.',
                ]);
            }
            $company->users()->attach($user->getKey(), [
                'status' => MembershipStatus::Active->value,
            ]);

            // Automatically grant default access to all active applications belonging to this company
            $companyApplications = $company->applications()->where('status', ApplicationStatus::Active->value)->get();
            foreach ($companyApplications as $application) {
                if (! $user->applications()->whereKey($application->id)->exists()) {
                    $user->applications()->attach($application->id, [
                        'status' => MembershipStatus::Active->value,
                    ]);
                }
            }

            IamActivityOccurred::dispatch('company.member_added', $user, ['company_id' => $company->public_id]);

            return $company->users()->whereKey($user->getKey())->firstOrFail();
        });
    }

    public function remove(Company $company, User $user): void
    {
        DB::transaction(function () use ($company, $user): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->getKey());
            $user = User::withTrashed()->lockForUpdate()->findOrFail($user->getKey());
            if (! $company->users()->whereKey($user->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => 'The user is not a member of this company.',
                ]);
            }
            if ($company->users()->detach($user->getKey()) > 0) {
                // Remove access from applications belonging to this company
                $companyAppIds = $company->applications()->pluck('id');
                if ($companyAppIds->isNotEmpty()) {
                    $user->applications()->detach($companyAppIds);
                }

                IamActivityOccurred::dispatch('company.member_removed', $user, ['company_id' => $company->public_id]);
            }
        });
    }
}
