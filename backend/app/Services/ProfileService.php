<?php

namespace App\Services;

use App\Events\IamActivityOccurred;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProfileService
{
    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes, User $actor): User
    {
        return DB::transaction(function () use ($user, $attributes, $actor): User {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $profile = $user->profile()->lockForUpdate()->first();
            $profileAttributes = Arr::only($attributes, ['first_name', 'last_name', 'phone']);
            if (array_key_exists('photo', $attributes)) {
                $profileAttributes['profile_photo'] = $attributes['photo'];
            }

            if ($profileAttributes !== []) {
                $before = $profile?->getAttributes() ?? [];
                $profile ??= $user->profile()->make();
                $profile->fill($profileAttributes)->save();
                $changed = array_keys(array_filter($profileAttributes, function (mixed $value, string $field) use ($before, $profile): bool {
                    return ($before[$field] ?? null) !== $profile->getAttribute($field);
                }, ARRAY_FILTER_USE_BOTH));
                if ($changed !== []) {
                    $changed = array_map(fn (string $field): string => $field === 'profile_photo' ? 'photo' : $field, $changed);
                    IamActivityOccurred::dispatch('user.profile_updated', $user, ['changed_fields' => $changed], $actor);
                }
            }

            return $user->refresh()->load('profile');
        });
    }
}
