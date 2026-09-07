<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Events\IamActivityOccurred;
use App\Models\Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ApplicationService
{
    /** @param array{search?: string|null, status?: string|null, per_page?: int|string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Application::query()
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

    /** @param array{name: string, slug: string, description?: string|null} $attributes */
    public function create(array $attributes): Application
    {
        return DB::transaction(function () use ($attributes): Application {
            $application = Application::query()->create($attributes);
            IamActivityOccurred::dispatch('application.created', $application);

            return $application;
        });
    }

    /** @param array{name?: string, slug?: string, description?: string|null} $attributes */
    public function update(Application $application, array $attributes): Application
    {
        return DB::transaction(function () use ($application, $attributes): Application {
            $application->update($attributes);

            $changed = array_keys($application->getChanges());
            $changed = array_values(array_diff($changed, ['updated_at', 'remember_token']));
            if ($changed !== []) {
                IamActivityOccurred::dispatch('application.updated', $application, ['changed_fields' => $changed]);
            }

            return $application->refresh();
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
