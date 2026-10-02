<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Http\Middleware\OidcAuthorizationTransaction;
use App\Models\Application;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\ExchangedOidcNonce;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OAuthAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_register_a_pkce_authorization_code_client_for_an_application(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $application = Application::factory()->create();

        $response = $this->actingAs($admin)->postJson("/api/admin/applications/{$application->public_id}/oauth-clients", [
            'name' => 'HRM web',
            'redirect_uris' => ['https://hrm.example.test/oauth/callback'],
            'confidential' => false,
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'HRM web');
        $this->assertDatabaseHas('oauth_clients', [
            'application_id' => $application->getKey(),
            'name' => 'HRM web',
        ]);
        $this->assertSame(['authorization_code', 'refresh_token'], $response->json('data.grant_types'));
        $this->assertNotNull($response->json('data.id'));
        $this->assertArrayNotHasKey('secret', $response->json('data'));

        $this->actingAs($admin)->patchJson("/api/admin/applications/{$application->public_id}/oauth-clients/{$response->json('data.id')}/revoke")
            ->assertNoContent();
        $this->assertDatabaseHas('oauth_clients', ['id' => $response->json('data.id'), 'revoked' => true]);
    }

    public function test_confidential_client_secret_is_returned_only_at_registration_and_not_in_resource_data(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $application = Application::factory()->create();

        $response = $this->actingAs($admin)->postJson("/api/admin/applications/{$application->public_id}/oauth-clients", [
            'name' => 'HRM server',
            'redirect_uris' => ['https://hrm.example.test/oauth/callback'],
            'confidential' => true,
        ])->assertCreated();

        $this->assertNotEmpty($response->json('client_secret'));
        $this->assertArrayNotHasKey('secret', $response->json('data'));
    }

    public function test_non_admin_cannot_register_a_client(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/admin/applications/{$application->public_id}/oauth-clients", [
                'name' => 'Nope',
                'redirect_uris' => ['https://hrm.example.test/callback'],
            ])->assertForbidden();
    }

    public function test_authorization_requires_exact_redirect_uri_pkce_and_effective_access(): void
    {
        [$user, $application, $client] = $this->fixture();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '=');
        $query = [
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://hrm.example.test/callback',
            'scope' => 'iam:read',
            'state' => 'opaque-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$query, 'redirect_uri' => 'https://evil.example.test/callback']))->assertBadRequest();
        foreach (['https://hrm.example.test/callback/', 'https://hrm.example.test/callback?x=1', 'http://hrm.example.test/callback', 'https://hrm.example.test:443/callback', 'https://hrm.example.test/callback/extra'] as $redirectUri) {
            $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$query, 'redirect_uri' => $redirectUri]))->assertBadRequest();
        }
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$query, 'scope' => 'iam:read unknown']))->assertBadRequest();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([...$query, 'code_challenge_method' => 'plain']))->assertBadRequest();

        DB::table('application_user')->where('user_id', $user->getKey())->where('application_id', $application->getKey())->update(['status' => 'inactive']);
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertForbidden();
    }

    public function test_openid_authorization_requires_nonce_while_existing_oauth_does_not(): void
    {
        [$user, , $client] = $this->fixture();
        $openid = [...$this->authorizationQuery($client), 'scope' => 'openid'];
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($openid))->assertBadRequest();

        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
            ...$this->authorizationQuery($client),
            'scope' => 'profile',
        ]))->assertBadRequest();

        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertOk();
    }

    public function test_oidc_nonce_is_bound_to_the_validated_client_redirect_and_authorization_code(): void
    {
        [$user, , $client] = $this->fixture();
        $nonce = 'nonce-for-client-a';
        $verifier = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-._~';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [...$this->authorizationQuery($client), 'scope' => 'openid profile email', 'nonce' => $nonce, 'code_challenge' => $challenge];

        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($user, 'web')->post('/oauth/authorize', [
            'auth_token' => $this->app['session.store']->get('authToken'),
            'nonce' => 'attacker-substituted-nonce',
        ])->assertRedirect();
        parse_str((string) parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);

        $authCode = DB::table('oauth_auth_codes')->where('client_id', $client->id)->latest('expires_at')->first();
        $this->assertSame($nonce, $authCode->nonce);
        $this->assertFalse(Schema::hasColumn('oauth_auth_codes', 'redirect_uri'));
        $this->assertSame($client->id, $authCode->client_id);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://hrm.example.test/callback',
            'code' => $redirectQuery['code'],
            'code_verifier' => $verifier,
        ])->assertOk();

        $this->assertDatabaseHas('oauth_auth_codes', ['id' => $authCode->id, 'nonce' => $nonce, 'revoked' => true]);
    }

    public function test_oidc_transaction_cannot_be_rebound_to_another_client_or_redirect(): void
    {
        [$user, $application, $client] = $this->fixture();
        $other = app(OAuthClientService::class)->create($application, [
            'name' => 'Other OIDC client',
            'redirect_uris' => ['https://other.example.test/callback'],
            'confidential' => false,
        ]);
        $nonce = 'bound-nonce';
        $verifier = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [...$this->authorizationQuery($client), 'scope' => 'openid', 'nonce' => $nonce, 'code_challenge' => $challenge];

        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($user, 'web')->post('/oauth/authorize', [
            'auth_token' => $this->app['session.store']->get('authToken'),
        ])->assertRedirect();
        parse_str((string) parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $other->id,
            'redirect_uri' => 'https://other.example.test/callback',
            'code' => $redirectQuery['code'],
            'code_verifier' => $verifier,
        ])->assertBadRequest();

        $authCode = DB::table('oauth_auth_codes')->where('client_id', $client->id)->latest('expires_at')->first();
        $this->assertSame($nonce, $authCode->nonce);
        $this->assertFalse(Schema::hasColumn('oauth_auth_codes', 'redirect_uri'));
    }

    public function test_inactive_application_cannot_authorize_even_with_a_retained_assignment(): void
    {
        [$user, $application, $client] = $this->fixture();
        $application->update(['status' => ApplicationStatus::Inactive]);
        $query = $this->authorizationQuery($client);

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertForbidden();
    }

    public function test_inactive_suspended_and_deleted_users_cannot_authorize(): void
    {
        foreach ([AccountStatus::Inactive, AccountStatus::Suspended] as $status) {
            [$user, , $client] = $this->fixture();
            $user->update(['status' => $status]);

            $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertForbidden();
        }

        [$deleted, , $client] = $this->fixture();
        $deleted->delete();
        $this->actingAs($deleted)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertForbidden();
    }

    public function test_malformed_authorization_client_ids_are_rejected_before_client_lookup(): void
    {
        [$user, , $client] = $this->fixture();
        foreach (['iam:read', 'openid'] as $scope) {
            foreach (['missing', 'arbitrary-client-text', '', null, ['unexpected-array'], '12345678-1234-1234-1234-123456789xyz'] as $clientId) {
                $query = [...$this->authorizationQuery($client), 'client_id' => $clientId, 'scope' => $scope, 'nonce' => 'valid-nonce'];
                DB::enableQueryLog();
                DB::flushQueryLog();
                try {
                    $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))
                        ->assertBadRequest()->assertExactJson(['error' => 'invalid_client']);
                    $clientQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'oauth_clients'));
                    $this->assertSame([], $clientQueries);
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
            }
        }
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_valid_nonexistent_uuid_clients_use_the_normal_client_lookup(): void
    {
        [$user, , $client] = $this->fixture();
        foreach ([(string) Str::uuid(), '00000000-0000-0000-0000-000000000000'] as $clientId) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $query = [...$this->authorizationQuery($client), 'client_id' => $clientId];
                $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))
                    ->assertBadRequest()->assertExactJson(['error' => 'invalid_client']);
                $clientQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'oauth_clients'));
                $this->assertCount(1, $clientQueries);
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
    }

    public function test_valid_client_ids_preserve_active_revoked_and_ineligible_errors(): void
    {
        [$user, $application, $client] = $this->fixture();
        $query = $this->authorizationQuery($client);
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $client->update(['revoked' => true]);
        $this->get('/oauth/authorize?'.http_build_query($query))
            ->assertBadRequest()->assertExactJson(['error' => 'invalid_client']);
        $client->update(['revoked' => false]);
        $application->update(['status' => ApplicationStatus::Inactive]);
        $this->get('/oauth/authorize?'.http_build_query($query))
            ->assertForbidden()->assertExactJson(['error' => 'unauthorized_client']);
    }

    public function test_invalid_client_and_unsupported_grant_are_rejected(): void
    {
        $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'missing',
            'redirect_uri' => 'https://hrm.example.test/callback',
            'code_challenge' => 'challenge',
            'code_challenge_method' => 'S256',
        ]))->assertBadRequest();

        $this->postJson('/oauth/token', ['grant_type' => 'client_credentials'])->assertBadRequest();

        [$user, , $client] = $this->fixture();
        $client->update(['revoked' => true]);
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertBadRequest();
    }

    public function test_guest_authorization_redirects_to_the_central_login_continuation(): void
    {
        [, , $client] = $this->fixture();

        $response = $this->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
    }

    public function test_login_continuation_cannot_be_replaced_with_an_external_redirect(): void
    {
        $this->withSession(['url.intended' => 'https://evil.example.test/oauth/authorize?client_id=evil'])
            ->get('/oauth/continue')
            ->assertRedirect('http://localhost:3000/dashboard');

        $this->get('/login?return_to=https://evil.example.test')
            ->assertRedirect('http://localhost:3000/login');
    }

    public function test_successful_central_login_continues_the_original_oauth_request(): void
    {
        [$user, , $client] = $this->fixture();
        $authorizationUrl = '/oauth/authorize?'.http_build_query($this->authorizationQuery($client));

        $this->get($authorizationUrl)->assertRedirect();
        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $continued = $this->get('/oauth/continue');
        $continued->assertRedirect();
        $continuedQuery = [];
        parse_str((string) parse_url($continued->headers->get('Location'), PHP_URL_QUERY), $continuedQuery);
        $expectedQuery = $this->authorizationQuery($client);
        ksort($expectedQuery);
        ksort($continuedQuery);
        $this->assertSame($expectedQuery, $continuedQuery);
    }

    public function test_pkce_authorization_code_is_single_use_and_exchanges_for_tokens(): void
    {
        [$user, $application, $client] = $this->fixture();
        $verifier = Str::random(48);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [...$this->authorizationQuery($client), 'code_challenge' => $challenge, 'state' => 'state-value'];

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($user, 'web')->post('/oauth/authorize', [
            'auth_token' => $this->app['session.store']->get('authToken'),
        ]);
        $approved->assertRedirect();
        parse_str((string) parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);
        $this->assertSame('state-value', $redirectQuery['state']);

        $tokenRequest = [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://hrm.example.test/callback',
            'code' => $redirectQuery['code'],
            'code_verifier' => $verifier,
        ];
        $missingVerifier = $tokenRequest;
        unset($missingVerifier['code_verifier']);
        $this->postJson('/oauth/token', $missingVerifier)->assertBadRequest();
        $this->postJson('/oauth/token', [...$tokenRequest, 'code_verifier' => 'wrong-verifier'])->assertBadRequest();
        $otherClient = app(OAuthClientService::class)->create($application, [
            'name' => 'Other client',
            'redirect_uris' => ['https://hrm.example.test/callback'],
            'confidential' => false,
        ]);
        $this->postJson('/oauth/token', [...$tokenRequest, 'client_id' => $otherClient->id])->assertBadRequest();
        $token = $this->postJson('/oauth/token', $tokenRequest);
        $token->assertOk()->assertJsonStructure(['token_type', 'access_token', 'expires_in', 'refresh_token']);
        $this->assertSame(900, $token->json('expires_in'));
        $refresh = DB::table('oauth_refresh_tokens')->latest('expires_at')->first();
        $this->assertNotNull($refresh);
        $this->assertTrue(now()->addDays(6)->lt($refresh->expires_at));
        $this->assertTrue(now()->addDays(8)->gt($refresh->expires_at));

        $this->postJson('/oauth/token', $tokenRequest)->assertStatus(400);
    }

    public function test_consent_requires_the_session_bound_token_and_deny_returns_oauth_error(): void
    {
        [$user, , $client] = $this->fixture();
        $query = $this->authorizationQuery($client);
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertOk();

        $this->actingAs($user)->post('/oauth/authorize', ['auth_token' => 'tampered'])->assertStatus(403);
        $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($user)
            ->post('/oauth/authorize', ['auth_token' => 'tampered'])
            ->assertStatus(403);

        [$user, , $client] = $this->fixture();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizationQuery($client)))->assertOk();
        $denied = $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($user)->delete('/oauth/authorize', [
            'auth_token' => $this->app['session.store']->get('authToken'),
        ]);
        $denied->assertRedirect();
        $this->assertStringContainsString('error=access_denied', (string) $denied->headers->get('Location'));
    }

    public function test_resource_requests_recheck_current_iam_application_eligibility(): void
    {
        Route::middleware(['auth:oauth', 'oauth.iam-access'])->get('/oauth-test-resource', fn () => response()->json(['ok' => true]));
        [$user, $application, $client] = $this->fixture();
        $tokens = $this->issueTokens($user, $client);

        $this->withOAuthToken($tokens['access_token'])->getJson('/oauth-test-resource')->assertOk();

        foreach ([
            fn () => DB::table('application_user')->where('user_id', $user->getKey())->where('application_id', $application->getKey())->update(['status' => 'inactive']),
            fn () => $user->update(['status' => AccountStatus::Inactive]),
            fn () => $user->update(['status' => AccountStatus::Suspended]),
            fn () => $application->update(['status' => ApplicationStatus::Inactive]),
        ] as $index => $mutation) {
            $mutation();
            $resource = $this->withOAuthToken($tokens['access_token'])->getJson('/oauth-test-resource');
            $this->assertSame(403, $resource->status(), 'mutation '.$index);
            $this->postJson('/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $client->id,
                'refresh_token' => $tokens['refresh_token'],
            ])->assertStatus(400);
            $user->update(['status' => AccountStatus::Active]);
            $application->update(['status' => ApplicationStatus::Active]);
            DB::table('application_user')->where('user_id', $user->getKey())->where('application_id', $application->getKey())->update(['status' => 'active']);
        }
    }

    public function test_revoked_assignment_denies_resource_and_refresh(): void
    {
        Route::middleware(['auth:oauth', 'oauth.iam-access'])->get('/oauth-test-resource', fn () => response()->json(['ok' => true]));
        [$user, $application, $client] = $this->fixture();
        $tokens = $this->issueTokens($user, $client);
        DB::table('application_user')->where('user_id', $user->getKey())->where('application_id', $application->getKey())->delete();

        $this->withOAuthToken($tokens['access_token'])->getJson('/oauth-test-resource')->assertForbidden();
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $tokens['refresh_token'],
        ])->assertStatus(400);
    }

    public function test_refresh_cannot_extend_ineligible_user_or_application_access(): void
    {
        foreach ([
            fn (User $user, Application $application) => $user->update(['status' => AccountStatus::Inactive]),
            fn (User $user, Application $application) => $user->update(['status' => AccountStatus::Suspended]),
            fn (User $user, Application $application) => $application->update(['status' => ApplicationStatus::Inactive]),
        ] as $mutation) {
            [$user, $application, $client] = $this->fixture();
            $tokens = $this->issueTokens($user, $client);
            $mutation($user, $application);

            $this->postJson('/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $client->id,
                'refresh_token' => $tokens['refresh_token'],
            ])->assertStatus(400);
        }
    }

    public function test_deleted_user_and_application_are_denied_at_resource_time_and_refresh(): void
    {
        Route::middleware(['auth:oauth', 'oauth.iam-access'])->get('/oauth-test-resource', fn () => response()->json(['ok' => true]));
        [$user, $application, $client] = $this->fixture();
        $tokens = $this->issueTokens($user, $client);

        $user->delete();
        $this->withOAuthToken($tokens['access_token'])->getJson('/oauth-test-resource')->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);

        [$user, $application, $client] = $this->fixture();
        $tokens = $this->issueTokens($user, $client);
        $application->delete();
        $this->withOAuthToken($tokens['access_token'])->getJson('/oauth-test-resource')->assertForbidden();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
    }

    public function test_client_revocation_revokes_existing_access_and_refresh_tokens(): void
    {
        Route::middleware(['auth:oauth', 'oauth.iam-access'])->get('/oauth-test-resource', fn () => response()->json(['ok' => true]));
        [$user, $application, $client] = $this->fixture();
        $tokens = $this->issueTokens($user, $client);

        app(OAuthClientService::class)->revoke($application, $client);

        $this->withOAuthToken($tokens['access_token'])->getJson('/oauth-test-resource')->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->assertDatabaseHas('oauth_access_tokens', ['client_id' => $client->id, 'revoked' => true]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['revoked' => true]);
    }

    public function test_missing_and_mismatched_oidc_transactions_never_create_codes(): void
    {
        foreach (['missing', 'client', 'redirect', 'nonce', 'transaction'] as $failure) {
            [$user, , $client] = $this->fixture();
            $token = $this->pendingOidc($user, $client, 'original-nonce');
            $key = OidcAuthorizationTransaction::SESSION_KEY;
            $pending = $this->app['session.store']->get($key);
            if ($failure === 'missing') {
                unset($pending[$token]);
            } elseif ($failure === 'transaction') {
                $pending[$token]['consent_token'] = 'another-transaction';
            } elseif ($failure === 'nonce') {
                $pending[$token]['metadata']['nonce'] = '';
            } else {
                $pending[$token]['metadata'][$failure === 'client' ? 'client_id' : 'redirect_uri'] = 'mismatch';
            }
            $this->app['session.store']->put($key, $pending);
            $this->withoutMiddleware(VerifyCsrfToken::class)->post('/oauth/authorize', ['auth_token' => $token])
                ->assertStatus($failure === 'transaction' ? 403 : 500);
            $this->assertDatabaseCount('oauth_auth_codes', 0);
            $this->assertArrayNotHasKey($token, $this->app['session.store']->get($key, []));
        }
    }

    public function test_nonce_write_failure_rolls_back_passports_insert(): void
    {
        [$user, , $client] = $this->fixture();
        $token = $this->pendingOidc($user, $client, 'persist-nonce');
        OAuthAuthCode::updating(function (): void {
            throw new \RuntimeException('Injected nonce persistence failure');
        });
        try {
            $this->withoutMiddleware(VerifyCsrfToken::class)->post('/oauth/authorize', ['auth_token' => $token])->assertStatus(500);
            $this->assertDatabaseCount('oauth_auth_codes', 0);
            $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
            $session = $this->app['session.store'];
            $saved = unserialize($session->getHandler()->read($session->getId()));
            $this->assertSame([], $saved[OidcAuthorizationTransaction::SESSION_KEY] ?? []);
        } finally {
            OAuthAuthCode::flushEventListeners();
        }
    }

    public function test_oidc_denial_and_invalid_consent_clear_the_selected_transaction(): void
    {
        [$user, , $client] = $this->fixture();
        $token = $this->pendingOidc($user, $client, 'denied-nonce');
        $this->withoutMiddleware(VerifyCsrfToken::class)->delete('/oauth/authorize', ['auth_token' => $token])->assertRedirect();
        $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
        $this->pendingOidc($user, $client, 'invalid-consent-nonce');
        $this->post('/oauth/authorize', ['auth_token' => 'tampered'])->assertForbidden();
        $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_invalid_oidc_authorization_leaves_no_new_transaction(): void
    {
        [$user, , $client] = $this->fixture();
        foreach ([['client_id' => 'missing'], ['redirect_uri' => 'https://evil.test'], ['nonce' => ''], ['nonce' => str_repeat('n', 256)], ['code_challenge' => 'invalid']] as $invalid) {
            $query = [...$this->authorizationQuery($client), 'scope' => 'openid', 'nonce' => 'valid', ...$invalid];
            $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertBadRequest();
            $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
            $this->assertNull(app('request')->attributes->get('oidc_transaction'));
        }
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_same_session_same_client_redirects_and_nonces_are_isolated(): void
    {
        [$user, , $client] = $this->fixture();
        $secondRedirect = 'https://hrm.example.test/second-callback';
        $client->update(['redirect_uris' => ['https://hrm.example.test/callback', $secondRedirect]]);
        $first = $this->pendingOidc($user, $client, 'first-nonce');
        $second = $this->pendingOidc($user, $client, 'second-nonce', $secondRedirect);
        $this->assertNotSame($first, $second);
        $key = OidcAuthorizationTransaction::SESSION_KEY;
        $this->assertCount(2, $this->app['session.store']->get($key));
        $this->withoutMiddleware(VerifyCsrfToken::class)->post('/oauth/authorize', ['auth_token' => $first])->assertRedirect();
        $this->assertDatabaseHas('oauth_auth_codes', ['client_id' => $client->id, 'nonce' => 'first-nonce']);
        $this->assertArrayHasKey($second, $this->app['session.store']->get($key));
        $this->assertArrayNotHasKey($first, $this->app['session.store']->get($key));
        $response = $this->post('/oauth/authorize', ['auth_token' => $second])->assertRedirect();
        $this->assertSame($secondRedirect, strtok($response->headers->get('Location'), '?'));
        $this->assertDatabaseHas('oauth_auth_codes', ['nonce' => 'second-nonce']);
        $this->assertSame([], $this->app['session.store']->get($key, []));
    }

    public function test_same_session_different_clients_complete_independently(): void
    {
        [$user, $application, $client] = $this->fixture();
        $other = app(OAuthClientService::class)->create($application, [
            'name' => 'Second client', 'redirect_uris' => ['https://other.test/callback'], 'confidential' => false,
        ]);
        $first = $this->pendingOidc($user, $client, 'client-one');
        $second = $this->pendingOidc($user, $other, 'client-two', 'https://other.test/callback');
        $this->withoutMiddleware(VerifyCsrfToken::class)->post('/oauth/authorize', ['auth_token' => $second])->assertRedirect();
        $this->post('/oauth/authorize', ['auth_token' => $first])->assertRedirect();
        $this->assertDatabaseHas('oauth_auth_codes', ['client_id' => $client->id, 'nonce' => 'client-one']);
        $this->assertDatabaseHas('oauth_auth_codes', ['client_id' => $other->id, 'nonce' => 'client-two']);
        $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
    }

    public function test_passport_redirect_is_authoritative_and_nonce_context_requires_successful_exchange(): void
    {
        [$user, , $client] = $this->fixture();
        $client->update(['redirect_uris' => ['https://hrm.example.test/callback', 'https://hrm.example.test/registered-second']]);
        $verifier = str_repeat('v', 48);
        $token = $this->pendingOidc($user, $client, 'validated-nonce', null, $verifier);
        $approved = $this->withoutMiddleware(VerifyCsrfToken::class)->post('/oauth/authorize', ['auth_token' => $token])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $redirect);
        $code = DB::table('oauth_auth_codes')->first();
        $request = [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'redirect_uri' => 'https://hrm.example.test/callback', 'code' => $redirect['code'], 'code_verifier' => $verifier,
            'nonce' => 'untrusted-token-nonce',
        ];
        DB::table('oauth_auth_codes')->where('id', $code->id)->update(['nonce' => 'stored-nonce']);
        foreach (['https://hrm.example.test/registered-second', 'https://hrm.example.test/callback/', 'https://hrm.example.test:443/callback', 'https://hrm.example.test/callback?x=1'] as $uri) {
            $this->postJson('/oauth/token', [...$request, 'redirect_uri' => $uri])->assertBadRequest();
            $this->assertNull(app(ExchangedOidcNonce::class)->nonce());
        }
        $this->postJson('/oauth/token', [...$request, 'code_verifier' => str_repeat('x', 48)])->assertBadRequest();
        $this->assertNull(app(ExchangedOidcNonce::class)->authorizationCodeId());
        $response = $this->postJson('/oauth/token', $request)->assertOk();
        $this->assertArrayNotHasKey('nonce', $response->json());
        $this->assertArrayHasKey('id_token', $response->json());
        $this->assertSame('stored-nonce', app(ExchangedOidcNonce::class)->nonce());
        $this->assertSame($code->id, app(ExchangedOidcNonce::class)->authorizationCodeId());
        $this->postJson('/oauth/token', $request)->assertBadRequest();
        $this->assertNull(app(ExchangedOidcNonce::class)->nonce());
        $this->assertFalse(Schema::hasColumn('oauth_auth_codes', 'redirect_uri'));
    }

    public function test_authorization_routes_lock_the_session_before_reading_pending_transactions(): void
    {
        foreach (['passport.authorizations.authorize', 'passport.authorizations.approve', 'passport.authorizations.deny'] as $name) {
            $this->assertSame(30, Route::getRoutes()->getByName($name)->locksFor());
        }
    }

    public function test_cancellation_and_expiration_do_not_consume_another_pending_request(): void
    {
        [$user, , $client] = $this->fixture();
        $first = $this->pendingOidc($user, $client, 'cancelled');
        $second = $this->pendingOidc($user, $client, 'pending');
        $this->withoutMiddleware(VerifyCsrfToken::class)->delete('/oauth/authorize', ['auth_token' => $first])->assertRedirect();
        $key = OidcAuthorizationTransaction::SESSION_KEY;
        $this->assertArrayHasKey($second, $this->app['session.store']->get($key));
        $pending = $this->app['session.store']->get($key);
        $pending[$second]['expires_at'] = time() - 1;
        $this->app['session.store']->put($key, $pending);
        $this->post('/oauth/authorize', ['auth_token' => $second])->assertForbidden();
        $this->assertSame([], $this->app['session.store']->get($key, []));
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_auto_approval_persists_nonce_without_retaining_transaction_state(): void
    {
        [$user, , $client] = $this->fixture();
        $verifier = str_repeat('v', 48);
        $token = $this->pendingOidc($user, $client, 'consented', null, $verifier);
        $approved = $this->withoutMiddleware(VerifyCsrfToken::class)->post('/oauth/authorize', ['auth_token' => $token]);
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $redirect);
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => 'https://hrm.example.test/callback',
            'code' => $redirect['code'], 'code_verifier' => $verifier,
        ])->assertOk();
        $query = [...$this->authorizationQuery($client), 'scope' => 'openid', 'nonce' => 'auto-approved'];
        $this->get('/oauth/authorize?'.http_build_query($query))->assertRedirect();
        $this->assertDatabaseHas('oauth_auth_codes', ['nonce' => 'auto-approved', 'revoked' => false]);
        $this->assertSame([], $this->app['session.store']->get(OidcAuthorizationTransaction::SESSION_KEY, []));
        $this->assertNull(app('request')->attributes->get('oidc_transaction'));
    }

    private function pendingOidc(User $user, OAuthClient $client, string $nonce, ?string $redirect = null, ?string $verifier = null): string
    {
        $query = [...$this->authorizationQuery($client), 'scope' => 'openid', 'nonce' => $nonce, 'prompt' => 'consent'];
        if ($redirect) {
            $query['redirect_uri'] = $redirect;
        }
        if ($verifier) {
            $query['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        }
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();

        return $this->app['session.store']->get('authToken');
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['status' => AccountStatus::Active]);
        $application = Application::factory()->create(['status' => ApplicationStatus::Active]);
        DB::table('application_user')->insert([
            'application_id' => $application->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $client = app(OAuthClientService::class)->create($application, [
            'name' => 'HRM',
            'redirect_uris' => ['https://hrm.example.test/callback'],
            'confidential' => false,
        ]);

        return [$user, $application, $client];
    }

    private function authorizationQuery(OAuthClient $client): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://hrm.example.test/callback',
            'state' => 'opaque-state',
            'code_challenge' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-._~',
            'code_challenge_method' => 'S256',
        ];
    }

    private function issueTokens(User $user, OAuthClient $client): array
    {
        $verifier = Str::random(48);
        $query = [...$this->authorizationQuery($client), 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($user, 'web')->post('/oauth/authorize', [
            'auth_token' => $this->app['session.store']->get('authToken'),
        ])->assertRedirect();
        parse_str((string) parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);

        return $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://hrm.example.test/callback',
            'code' => $redirectQuery['code'],
            'code_verifier' => $verifier,
        ])->assertOk()->json();
    }

    private function withOAuthToken(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
