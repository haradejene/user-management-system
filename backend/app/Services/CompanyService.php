<?php

namespace App\Services;

use App\Enums\MembershipStatus;
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
        return DB::transaction(fn (): Company => Company::query()->create($attributes));
    }

    /** @param array{name?: string} $attributes */
    public function update(Company $company, array $attributes): Company
    {
        return DB::transaction(function () use ($company, $attributes): Company {
            $company->update($attributes);

            return $company->refresh();
        });
    }

    public function changeStatus(Company $company, MembershipStatus $status): Company
    {
        return DB::transaction(function () use ($company, $status): Company {
            $company->update(['status' => $status]);

            return $company->refresh();
        });
    }
}
