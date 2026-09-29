<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\OAuthClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

class OAuthClientService
{
    public function __construct(private readonly ClientRepository $passportClients) {}

    /** @param array{name: string, redirect_uris: list<string>, confidential?: bool} $attributes */
    public function create(Application $application, array $attributes): OAuthClient
    {
        return DB::transaction(function () use ($application, $attributes): OAuthClient {
            $application = Application::query()->lockForUpdate()->findOrFail($application->getKey());
            abort_if($application->trashed(), 404);
            abort_if($application->status !== ApplicationStatus::Active, 422);
            $confidential = (bool) ($attributes['confidential'] ?? false);
            $client = new OAuthClient;
            $client->forceFill([
                'application_id' => $application->getKey(),
                'name' => $attributes['name'],
                'provider' => null,
                'redirect_uris' => array_values(array_unique($attributes['redirect_uris'])),
                'grant_types' => ['authorization_code', 'refresh_token'],
                'revoked' => false,
            ]);
            $client->secret = $confidential ? Str::random(40) : null;
            $client->save();

            return $client;
        });
    }

    public function revoke(Application $application, OAuthClient $client): void
    {
        DB::transaction(function () use ($application, $client): void {
            $locked = OAuthClient::query()->lockForUpdate()->findOrFail($client->getKey());
            abort_unless((int) $locked->application_id === (int) $application->getKey(), 404);
            $this->passportClients->delete($locked);
        });
    }
}
