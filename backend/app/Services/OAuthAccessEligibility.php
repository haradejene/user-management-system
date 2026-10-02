<?php

namespace App\Services;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;

class OAuthAccessEligibility
{
    public function __construct(private readonly ApplicationAccessService $access) {}

    public function user(?string $userId, string $clientId, bool $lock = false): ?User
    {
        if (! Str::isUuid($clientId) || $userId === null) {
            return null;
        }

        if ($lock) {
            $connection = Passport::token()->getConnection();
            if ($connection->transactionLevel() === 0
                || $connection->getPdo() !== (new User)->getConnection()->getPdo()
                || $connection->getPdo() !== (new Application)->getConnection()->getPdo()
                || $connection->getPdo() !== (new OAuthClient)->getConnection()->getPdo()
                || $connection->getPdo() !== DB::connection()->getPdo()) {
                throw new \LogicException('OAuth issuance requires one transactional IAM database connection.');
            }
        }

        $client = OAuthClient::query()->when($lock, fn ($query) => $query->sharedLock())->find($clientId);
        $user = User::withTrashed()->when($lock, fn ($query) => $query->lockForUpdate())->find($userId);
        $application = $client
            ? Application::withTrashed()->when($lock, fn ($query) => $query->lockForUpdate())->find($client->application_id)
            : null;

        if (! $client || $client->revoked || ! $user || ! $application) {
            return null;
        }

        if ($lock) {
            // Hold lifecycle and assignment rows until issuance commits. The
            // established IAM predicate remains the authority for eligibility.
            DB::table('application_user')->where('user_id', $user->getKey())
                ->where('application_id', $application->getKey())->lockForUpdate()->first();
        }

        return $this->access->isAllowed($user, $application) ? $user : null;
    }

    public function requireUser(?string $userId, string $clientId): User
    {
        return $this->user($userId, $clientId, true)
            ?? throw OAuthServerException::accessDenied('Current application access is required.');
    }
}
