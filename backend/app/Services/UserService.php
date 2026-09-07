<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Events\IamActivityOccurred;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    /** @param array{search?: string|null, status?: string|null, per_page?: int|string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = User::query()->create($attributes);
            IamActivityOccurred::dispatch('user.created', $user);

            return $user;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $user->update(Arr::where($attributes, fn (mixed $value): bool => $value !== null));

            $changed = array_keys($user->getChanges());
            $changed = array_values(array_diff($changed, ['updated_at', 'remember_token']));
            if ($changed !== []) {
                IamActivityOccurred::dispatch('user.updated', $user, ['changed_fields' => $changed]);
            }

            return $user->refresh();
        });
    }

    public function changeStatus(User $user, AccountStatus $status, User $actor): User
    {
        if ($user->is($actor) && $status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'status' => 'You cannot deactivate or suspend your own administrator account.',
            ]);
        }

        return DB::transaction(function () use ($user, $status, $actor): User {
            $previous = $user->status->value;
            $user->update(['status' => $status]);
            if ($previous !== $status->value) {
                IamActivityOccurred::dispatch('user.status_changed', $user, [
                    'previous_status' => $previous,
                    'status' => $status->value,
                ], $actor);
            }

            return $user->refresh();
        });
    }
}
