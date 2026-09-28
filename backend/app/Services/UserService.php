<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Events\IamActivityOccurred;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    /** @param array{search?: string|null, status?: string|null, per_page?: int|string|null, page?: int|string|null} $filters */
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
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1))
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
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $attributes = Arr::only(Arr::where($attributes, fn (mixed $value): bool => $value !== null), ['name', 'email', 'password']);
            if (isset($attributes['email'])) {
                $attributes['email'] = mb_strtolower(trim($attributes['email']));
                if ($attributes['email'] !== mb_strtolower(trim($user->email))) {
                    $user->email_verified_at = null;
                }
            }
            $user->fill($attributes)->save();

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
        abort_if($actor->trashed() || ! $actor->exists, 403);
        abort_if($user->trashed() || ! $user->exists, 404);

        return DB::transaction(function () use ($user, $status, $actor): User {
            $actorVersion = $actor->session_version;
            // Lock in a common order. An eligible, distinct actor must remain active
            // until commit, so concurrent administrators cannot disable each other.
            $locked = User::withTrashed()->whereIn('id', [$actor->getKey(), $user->getKey()])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $locked->get($actor->getKey());
            abort_unless($actor?->isCentralIamAdministrator(), 403);
            abort_unless($actor->session_version === $actorVersion, 403);
            $user = $locked->get($user->getKey());
            abort_if(! $user || $user->trashed(), 404);

            if ($user->is($actor) && $status !== AccountStatus::Active) {
                throw ValidationException::withMessages([
                    'status' => 'You cannot deactivate or suspend your own administrator account.',
                ]);
            }
            if ($user->status === AccountStatus::Suspended && $status === AccountStatus::Inactive) {
                throw ValidationException::withMessages([
                    'status' => 'A suspended account cannot be deactivated. Explicitly release the suspension after review.',
                ]);
            }

            $previous = $user->status->value;
            if ($previous !== $status->value) {
                $user->status = $status;
                if ($status !== AccountStatus::Active) {
                    $user->session_version++;
                    $user->remember_token = Str::random(60);
                }
                $user->save();
                IamActivityOccurred::dispatch('user.status_changed', $user, [
                    'previous_status' => $previous,
                    'status' => $status->value,
                ] + ($previous === AccountStatus::Suspended->value ? ['reason' => 'suspension_released'] : []), $actor);
            }

            return $user->refresh();
        });
    }
}
