<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Events\IamActivityOccurred;
use App\Models\Application;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationAccessService
{
    public function applications(User $user, int $perPage, ?array $applicationIds = null): LengthAwarePaginator
    {
        $paginator = $user->applications()
            ->when($applicationIds !== null, fn ($query) => $query->whereIn('applications.public_id', $applicationIds))
            ->orderBy('name')
            ->orderBy('applications.id')
            ->paginate($perPage);

        return $this->decorateApplications($paginator, $user);
    }

    public function users(Application $application, int $perPage): LengthAwarePaginator
    {
        $paginator = $application->users()
            ->orderBy('name')
            ->orderBy('users.id')
            ->paginate($perPage);

        $paginator->setCollection($paginator->getCollection()->map(function (User $user) use ($application): User {
            $state = $user->applicationAccessState($application);
            $user->setAttribute('assignment_exists', $state['assigned']);
            $user->setAttribute('effective_access', $state['effective_access']);
            $user->setAttribute('ineffective_reason', $state['ineffective_reason']);

            return $user;
        }));

        return $paginator;
    }

    public function grant(User $user, Application $application, User $administrator): Application
    {
        abort_unless($administrator->isCentralIamAdministrator(), 403);

        return DB::transaction(function () use ($user, $application, $administrator): Application {
            $lockedUsers = User::withTrashed()->whereIn('id', [$user->getKey(), $administrator->getKey()])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $lockedUsers->get($user->getKey());
            $administrator = $lockedUsers->get($administrator->getKey());
            $application = Application::withTrashed()->lockForUpdate()->find($application->getKey());

            abort_unless($administrator?->isCentralIamAdministrator(), 403);
            abort_if(! $user || $user->trashed(), 404);
            if ($user->status !== AccountStatus::Active) {
                throw ValidationException::withMessages(['user' => 'Access cannot be granted to an inactive or suspended user.']);
            }
            if (! $application || $application->trashed() || $application->status !== ApplicationStatus::Active) {
                throw ValidationException::withMessages([
                    'application_id' => 'Access cannot be granted to an inactive application.',
                ]);
            }
            if (DB::table('application_user')->where('user_id', $user->getKey())->where('application_id', $application->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'application_id' => 'The user already has access to this application.',
                ]);
            }

            $user->applications()->attach($application->getKey(), [
                'status' => MembershipStatus::Active->value,
                'granted_by' => $administrator->getKey(),
            ]);

            IamActivityOccurred::dispatch('application.access_granted', $user, ['application_id' => $application->public_id], $administrator);

            $result = $user->applications()->whereKey($application->getKey())->firstOrFail();
            $this->decorateApplication($result, $user);

            return $result;
        });
    }

    public function revoke(User $user, Application $application): void
    {
        DB::transaction(function () use ($user, $application): void {
            $lockedUser = User::withTrashed()->lockForUpdate()->find($user->getKey());
            $lockedApplication = Application::withTrashed()->lockForUpdate()->find($application->getKey());
            if (! $lockedUser || ! $lockedApplication) {
                return;
            }
            if ($lockedUser->applications()->detach($lockedApplication->getKey()) > 0) {
                IamActivityOccurred::dispatch('application.access_revoked', $user, ['application_id' => $application->public_id]);
            }
        });
    }

    public function isAllowed(User $user, Application $application): bool
    {
        return $user->hasAccessToApplication($application);
    }

    /** @return LengthAwarePaginator<Application> */
    private function decorateApplications(LengthAwarePaginator $paginator, User $user): LengthAwarePaginator
    {
        $paginator->setCollection($paginator->getCollection()->map(function (Application $application) use ($user): Application {
            $this->decorateApplication($application, $user);

            return $application;
        }));

        return $paginator;
    }

    private function decorateApplication(Application $application, User $user): void
    {
        $state = $user->applicationAccessState($application);
        $application->setAttribute('assignment_exists', $state['assigned']);
        $application->setAttribute('effective_access', $state['effective_access']);
        $application->setAttribute('ineffective_reason', $state['ineffective_reason']);
    }
}
