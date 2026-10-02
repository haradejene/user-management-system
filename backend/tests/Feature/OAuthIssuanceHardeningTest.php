<?php

namespace Tests\Feature;

use App\Http\Middleware\OidcAuthorizationTransaction;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Laravel\Passport\Events\AccessTokenCreated;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OAuthIssuanceHardeningTest extends TestCase
{
    use RefreshDatabase;

    public static function lifecycleChanges(): array
    {
        $cases = [];
        foreach (['inactive_user', 'suspended_user', 'deleted_user', 'revoked_assignment', 'inactive_assignment', 'inactive_application', 'deleted_application', 'revoked_client'] as $change) {
            foreach (['openid', 'iam:read'] as $scope) {
                $cases[$change.' '.$scope] = [$change, $scope];
            }
        }

        return $cases;
    }

    #[DataProvider('lifecycleChanges')]
    public function test_lifecycle_changes_before_approval_prevent_code_creation(string $change, string $scope): void
    {
        [$user, $application, $client] = $this->fixture();
        $pending = $this->pending($user, $client, $scope);
        $this->change($change, $user, $application, $client);

        $this->post('/oauth/authorize', ['auth_token' => $pending])->assertUnauthorized()->assertJsonPath('error', 'access_denied');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
    }

    #[DataProvider('lifecycleChanges')]
    public function test_lifecycle_changes_before_exchange_prevent_all_token_issuance(string $change, string $scope): void
    {
        [$user, $application, $client] = $this->fixture();
        $parameters = $this->approve($client, $this->pending($user, $client, $scope));
        $this->change($change, $user, $application, $client);

        $response = $this->postJson('/oauth/token', $parameters)->assertUnauthorized();
        $response->assertJsonPath('error', $change === 'revoked_client' ? 'invalid_client' : 'access_denied');
        $this->assertArrayNotHasKey('id_token', $response->json());
        $this->assertArrayNotHasKey('access_token', $response->json());
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
    }

    public function test_approval_cannot_complete_another_authenticated_users_pending_request(): void
    {
        [$user, , $client] = $this->fixture();
        $pending = $this->pending($user, $client, 'openid');
        $this->actingAs(User::factory()->create(), 'web');
        $this->post('/oauth/authorize', ['auth_token' => $pending])->assertUnauthorized()->assertJsonPath('error', 'access_denied');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
    }

    public static function invalidIdentifiers(): array
    {
        $cases = [];
        foreach (['authorization_code', 'refresh_token'] as $grant) {
            foreach (['hello', '123', 'missing', 'not-a-uuid', ''] as $identifier) {
                foreach (['body', 'basic'] as $source) {
                    $cases[$grant.' '.$source.' '.($identifier ?: 'empty')] = [$grant, $source, $identifier];
                }
            }
            $cases[$grant.' missing'] = [$grant, 'missing', null];
            $cases[$grant.' malformed basic'] = [$grant, 'malformed_basic', null];
        }

        return $cases;
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_malformed_token_client_identifiers_never_reach_client_queries(string $grant, string $source, ?string $identifier): void
    {
        $parameters = ['grant_type' => $grant];
        $headers = [];
        if ($source === 'body') {
            $parameters['client_id'] = $identifier;
        } elseif ($source === 'basic') {
            $headers['Authorization'] = 'Basic '.base64_encode($identifier.':test-secret');
        } elseif ($source === 'malformed_basic') {
            $headers['Authorization'] = 'Basic !!!';
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->postJson('/oauth/token', $parameters, $headers);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertContains($response->getStatusCode(), [400, 401]);
        $this->assertContains($response->json('error'), ['invalid_client', 'invalid_request']);
        foreach (['SQLSTATE', '22P02', 'select ', 'stack', 'exception'] as $internal) {
            $this->assertStringNotContainsString($internal, $response->getContent());
        }
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'oauth_clients'))));
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
    }

    public function test_valid_unregistered_uuid_uses_passports_normal_client_validation(): void
    {
        foreach (['authorization_code', 'refresh_token'] as $grant) {
            $this->postJson('/oauth/token', ['grant_type' => $grant, 'client_id' => (string) Str::uuid()])
                ->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        }
    }

    public function test_failed_id_token_rendering_rolls_back_tokens_and_code_consumption(): void
    {
        [$user, , $client] = $this->fixture();
        $parameters = $this->approve($client, $this->pending($user, $client, 'openid'));
        DB::table('oauth_auth_codes')->update(['nonce' => null]);
        $this->postJson('/oauth/token', $parameters)->assertStatus(500)->assertJsonPath('error', 'server_error');
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
        $this->assertDatabaseHas('oauth_auth_codes', ['client_id' => $client->id, 'revoked' => false]);
        DB::table('oauth_auth_codes')->update(['nonce' => 'bound-nonce']);
        $this->postJson('/oauth/token', $parameters)->assertOk()->assertJsonStructure(['id_token']);
    }

    public function test_id_token_generation_rechecks_eligibility_and_rolls_back_partial_issuance(): void
    {
        [$user, , $client] = $this->fixture();
        $parameters = $this->approve($client, $this->pending($user, $client, 'openid'));
        Event::listen(AccessTokenCreated::class, fn () => $user->update(['status' => 'suspended']));

        $this->postJson('/oauth/token', $parameters)->assertUnauthorized()->assertJsonPath('error', 'access_denied');
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
        $this->assertDatabaseHas('oauth_auth_codes', ['revoked' => false]);
    }

    public function test_invalid_pkce_does_not_consume_the_code_or_create_tokens(): void
    {
        [$user, , $client] = $this->fixture();
        $parameters = $this->approve($client, $this->pending($user, $client, 'openid'));
        $this->postJson('/oauth/token', [...$parameters, 'code_verifier' => str_repeat('x', 48)])->assertBadRequest();
        $this->assertDatabaseHas('oauth_auth_codes', ['revoked' => false]);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
        $this->postJson('/oauth/token', $parameters)->assertOk();
    }

    public function test_eligibility_rejection_permanently_revokes_the_refresh_token(): void
    {
        [$user, , $client] = $this->fixture();
        $tokens = $this->postJson('/oauth/token', $this->approve($client, $this->pending($user, $client, 'openid')))->assertOk();
        $refresh = ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'refresh_token' => $tokens->json('refresh_token')];
        $user->update(['status' => 'inactive']);
        $this->postJson('/oauth/token', $refresh)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseHas('oauth_refresh_tokens', ['revoked' => true]);
        $user->update(['status' => 'active']);
        $this->postJson('/oauth/token', $refresh)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active']);
        $client = app(OAuthClientService::class)->create($application, ['name' => 'Hardening test', 'redirect_uris' => ['https://client.example.test/callback']]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        return [$user, $application, $client];
    }

    private function pending(User $user, OAuthClient $client, string $scope): string
    {
        $query = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'scope' => $scope, 'nonce' => 'bound-nonce', 'state' => 'bound-state', 'prompt' => 'consent', 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 48), true)), '+/', '-_'), '=')];
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();

        return $this->app['session.store']->get('authToken');
    }

    private function approve(OAuthClient $client, string $pending): array
    {
        $response = $this->post('/oauth/authorize', ['auth_token' => $pending])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'code' => $query['code'], 'code_verifier' => str_repeat('v', 48)];
    }

    private function change(string $change, User $user, Application $application, OAuthClient $client): void
    {
        match ($change) {
            'inactive_user' => $user->update(['status' => 'inactive']),
            'suspended_user' => $user->update(['status' => 'suspended']),
            'deleted_user' => $user->delete(),
            'revoked_assignment' => $user->applications()->detach($application),
            'inactive_assignment' => $user->applications()->updateExistingPivot($application->id, ['status' => 'inactive']),
            'inactive_application' => $application->update(['status' => 'inactive']),
            'deleted_application' => $application->delete(),
            'revoked_client' => $client->update(['revoked' => true]),
        };
        // Separate HTTP requests do not share Passport's request-local once cache.
        Once::instance()->flush();
    }
}
