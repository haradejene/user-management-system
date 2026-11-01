<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Events\IamActivityOccurred;
use App\Models\Application;
use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ApplicationService
{
    /** @param array{search?: string|null, status?: string|null, per_page?: int|string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Application::query()
            ->with('company')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
    }

    /** @param array{company_id?: string|int|null, name: string, slug: string, description?: string|null} $attributes */
    public function create(array $attributes): Application
    {
        return DB::transaction(function () use ($attributes): Application {
            if (array_key_exists('company_id', $attributes) && is_string($attributes['company_id'])) {
                $company = Company::where('public_id', $attributes['company_id'])->first();
                $attributes['company_id'] = $company?->id;
            }

            $application = Application::query()->create($attributes);
            IamActivityOccurred::dispatch('application.created', $application);

            if ($application->company_id) {
                $company = Company::find($application->company_id);
                if ($company) {
                    $members = $company->users()->where('users.status', AccountStatus::Active->value)->get();
                    foreach ($members as $member) {
                        if (! $member->applications()->whereKey($application->id)->exists()) {
                            $member->applications()->attach($application->id, [
                                'status' => MembershipStatus::Active->value,
                            ]);
                        }
                    }
                }
            }

            return $application->load('company');
        });
    }

    /** @param array{company_id?: string|int|null, name?: string, slug?: string, description?: string|null} $attributes */
    public function update(Application $application, array $attributes): Application
    {
        return DB::transaction(function () use ($application, $attributes): Application {
            if (array_key_exists('company_id', $attributes) && is_string($attributes['company_id'])) {
                $company = Company::where('public_id', $attributes['company_id'])->first();
                $attributes['company_id'] = $company?->id;
            }

            $previousCompanyId = $application->company_id;
            $application->update($attributes);

            if ($application->company_id && $application->company_id !== $previousCompanyId) {
                $company = Company::find($application->company_id);
                if ($company) {
                    $members = $company->users()->where('users.status', AccountStatus::Active->value)->get();
                    foreach ($members as $member) {
                        if (! $member->applications()->whereKey($application->id)->exists()) {
                            $member->applications()->attach($application->id, [
                                'status' => MembershipStatus::Active->value,
                            ]);
                        }
                    }
                }
            }

            $changed = array_keys($application->getChanges());
            $changed = array_values(array_diff($changed, ['updated_at', 'remember_token']));
            if ($changed !== []) {
                IamActivityOccurred::dispatch('application.updated', $application, ['changed_fields' => $changed]);
            }

            return $application->refresh()->load('company');
        });
    }

    public function changeStatus(Application $application, ApplicationStatus $status): Application
    {
        return DB::transaction(function () use ($application, $status): Application {
            $previous = $application->status->value;
            $application->update(['status' => $status]);
            if ($previous !== $status->value) {
                IamActivityOccurred::dispatch('application.status_changed', $application, [
                    'previous_status' => $previous,
                    'status' => $status->value,
                ]);
            }

            return $application->refresh();
        });
    }
}
