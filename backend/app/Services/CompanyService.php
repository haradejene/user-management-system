<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Events\IamActivityOccurred;
use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    /** @param array{search?: string|null, status?: string|null, per_page?: int|string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Company::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
    }

    /** @param array{name: string} $attributes */
    public function create(array $attributes): Company
    {
        return DB::transaction(function () use ($attributes): Company {
            $company = Company::query()->create($attributes);
            IamActivityOccurred::dispatch('company.created', $company);

            return $company;
        });
    }

    /** @param array{name?: string} $attributes */
    public function update(Company $company, array $attributes): Company
    {
        return DB::transaction(function () use ($company, $attributes): Company {
            $company->update($attributes);

            $changed = array_keys($company->getChanges());
            $changed = array_values(array_diff($changed, ['updated_at', 'remember_token']));
            if ($changed !== []) {
                IamActivityOccurred::dispatch('company.updated', $company, ['changed_fields' => $changed]);
            }

            return $company->refresh();
        });
    }

    public function changeStatus(Company $company, MembershipStatus $status): Company
    {
        return DB::transaction(function () use ($company, $status): Company {
            $previous = $company->status->value;
            $company->update(['status' => $status]);
            if ($previous !== $status->value) {
                IamActivityOccurred::dispatch('company.status_changed', $company, [
                    'previous_status' => $previous,
                    'status' => $status->value,
                ]);
            }

            return $company->refresh();
        });
    }
}
