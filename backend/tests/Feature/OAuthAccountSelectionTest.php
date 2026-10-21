<?php

namespace Tests\Feature;

use App\Http\Middleware\OidcAuthorizationTransaction;
use App\Http\Middleware\SelectOAuthAccount;
use App\Models\Application;
use App\Models\User;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OAuthAccountSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $prompt = 'select_account'): array
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        DB::table('application_user')->insert([
            'application_id' => $application->id, 'user_id' => $user->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $client = app(OAuthClientService::class)->create($application, [
            'name' => 'HRM web', 'redirect_uris' => ['https://hrm.example.test/callback'], 'confidential' => false,
        ]);
        $query = [
            'response_type' => 'code', 'client_id' => $client->id,
            'redirect_uri' => 'https://hrm.example.test/callback',
            'scope' => 'openid profile email', 'nonce' => 'test-bound-nonce', 'state' => 'test-bound-state',
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'prompt' => $prompt,
        ];

        return [$user, $application, $query];
    }

    private function url(array $query): string
    {
        return '/oauth/authorize?'.http_build_query($query);
    }

    private function choiceToken(): string
    {
        return array_key_last(session(SelectOAuthAccount::PENDING));
    }

    public function test_authenticated_selection_lists_only_the_current_session_and_preserves_the_request(): void
    {
        [$user, , $query] = $this->fixture();
        $other = User::factory()->create();
        $this->actingAs($user)->get($this->url($query))
            ->assertRedirect()->assertDontSee($other->email);
        $token = $this->choiceToken();
        $this->getJson('/oauth/account-choice/'.$token)->assertOk()
            ->assertJsonStructure(['application'])->assertJsonMissingPath('users');
        $response = $this->post('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current', 'user_id' => $other->id])
            ->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $continued);
        $this->assertSame($query, $continued);
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertSee('Review what')->assertSee('HRM web')->assertSee('Read your email address')
            ->assertSee('Approve')->assertSee('Cancel')->assertSee('Choose an account');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('test-bound-nonce', current(session('oidc_authorization_transactions'))['metadata']['nonce']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->post('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current'])->assertStatus(410);
    }

    public function test_guest_must_authenticate_before_any_account_is_offered(): void
    {
        [$user, , $query] = $this->fixture();
        $this->get($this->url($query))->assertRedirect()->assertDontSee($user->email);
        $this->assertGuest('web');
        $token = $this->choiceToken();
        $this->post('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current'])->assertStatus(419);
        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $response = $this->post('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Review what');
    }

    public function test_using_another_account_signs_out_and_preserves_the_original_oidc_continuation(): void
    {
        [$user, , $query] = $this->fixture();
        $this->actingAs($user)->get($this->url($query))->assertRedirect();
        $this->post('/oauth/select-account', ['choice_token' => $this->choiceToken(), 'choice' => 'other'])
            ->assertRedirect('/login');
        $this->assertGuest('web');
        parse_str(parse_url(session('url.intended'), PHP_URL_QUERY), $continued);
        $this->assertSame($query, $continued);
        $this->assertEmpty(session(SelectOAuthAccount::PENDING, []));
        $this->get($this->url($query))->assertRedirect();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_selection_does_not_bypass_reauthenticated_identity_application_access(): void
    {
        [$user, , $query] = $this->fixture();
        $other = User::factory()->create();
        $this->actingAs($user)->get($this->url($query));
        $this->post('/oauth/select-account', ['choice_token' => $this->choiceToken(), 'choice' => 'other']);
        $this->actingAs($other)->get($this->url($query))->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_access_is_rechecked_after_selection_and_no_assignment_is_granted(): void
    {
        [$user, $application, $query] = $this->fixture();
        $this->actingAs($user)->get($this->url($query));
        $response = $this->post('/oauth/select-account', ['choice_token' => $this->choiceToken(), 'choice' => 'current']);
        DB::table('application_user')->where('application_id', $application->id)->update(['status' => 'inactive']);
        $this->get($response->headers->get('Location'))->assertForbidden();
        $this->assertDatabaseHas('application_user', ['application_id' => $application->id, 'status' => 'inactive']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_inactive_or_expired_session_cannot_select_an_account(): void
    {
        [$user, , $query] = $this->fixture();
        $this->actingAs($user)->get($this->url($query));
        $token = $this->choiceToken();
        DB::table('users')->where('id', $user->id)->update(['session_version' => $user->session_version + 1]);
        $this->post('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current'])->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_cancel_returns_only_an_error_and_original_state_to_the_registered_callback(): void
    {
        [$user, , $query] = $this->fixture();
        $this->actingAs($user)->get($this->url($query));
        $this->post('/oauth/select-account', ['choice_token' => $this->choiceToken(), 'choice' => 'cancel'])
            ->assertRedirect('https://hrm.example.test/callback?error=access_denied&state=test-bound-state');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_browser_consent_cancel_form_survives_another_authorization_tab(): void
    {
        [$user, , $query] = $this->fixture('consent');
        $first = $this->actingAs($user)->get($this->url($query))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($first->getContent());
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[input[@name="_method" and @value="DELETE"]]')->item(0);
        $this->assertNotNull($form);
        $body = [];
        foreach ($xpath->query('.//input', $form) as $input) {
            $body[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->get($this->url([...$query, 'state' => 'another-tab', 'nonce' => 'another-nonce']))->assertOk();
        Request::enableHttpMethodParameterOverride();
        $this->post($form->getAttribute('action'), $body)->assertRedirect();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    private function enforceCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_replayed_cancel_reproduces_passports_token_error_without_the_stale_page_handler(): void
    {
        [$user, , $query] = $this->fixture('consent');
        $this->actingAs($user)->get($this->url($query));
        $token = session('authToken');
        $this->delete('/oauth/authorize', ['auth_token' => $token])->assertRedirect();
        $this->withoutMiddleware(OidcAuthorizationTransaction::class)
            ->delete('/oauth/authorize', ['auth_token' => $token])->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_browser_cancel_enforces_csrf_and_consumes_only_its_own_request(): void
    {
        $this->enforceCsrf();
        [$user, , $query] = $this->fixture('consent');
        $this->actingAs($user)->withSession(['_token' => 'csrf-test-only'])->get($this->url($query));
        $token = session('authToken');
        $this->get($this->url([...$query, 'nonce' => 'second', 'state' => 'second']));
        $other = session('authToken');
        Request::enableHttpMethodParameterOverride();
        $body = ['_method' => 'DELETE', 'auth_token' => $token];
        $this->post('/oauth/authorize', $body)->assertStatus(419);
        $cancelled = $this->post('/oauth/authorize', [...$body, '_token' => 'csrf-test-only'])->assertRedirect();
        parse_str(parse_url($cancelled->headers->get('Location'), PHP_URL_QUERY), $result);
        $this->assertSame('access_denied', $result['error']);
        $this->assertSame('test-bound-state', $result['state']);
        $this->assertArrayNotHasKey('code', $result);
        $this->post('/oauth/authorize', [...$body, '_token' => 'csrf-test-only'])->assertStatus(410)
            ->assertSee('This request is already closed');
        $this->assertArrayHasKey($other, session(OidcAuthorizationTransaction::SESSION_KEY));
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_choice_cancellation_is_distinct_and_csrf_protected_even_for_a_guest(): void
    {
        $this->enforceCsrf();
        [, , $query] = $this->fixture();
        $this->withSession(['_token' => 'csrf-test-only'])->get($this->url($query))->assertRedirect();
        $token = $this->choiceToken();
        $this->postJson('/oauth/select-account', ['choice_token' => $token, 'choice' => 'cancel'])->assertStatus(419);
        $this->postJson('/oauth/select-account', ['_token' => 'csrf-test-only', 'choice_token' => $token, 'choice' => 'cancel'])
            ->assertOk()->assertJsonPath('redirect_url', 'https://hrm.example.test/callback?error=access_denied&state=test-bound-state');
        $this->assertGuest('web');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->getJson('/oauth/account-choice/'.$token)->assertStatus(410);
    }

    public function test_choose_another_account_preserves_consent_and_requires_successful_credentials(): void
    {
        [$user, $application, $query] = $this->fixture('consent');
        $other = User::factory()->create();
        DB::table('application_user')->insert(['application_id' => $application->id, 'user_id' => $other->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->get($this->url($query))->assertOk();
        $authToken = session('authToken');
        $this->post('/oauth/change-account', ['auth_token' => $authToken])->assertRedirect();
        $token = $this->choiceToken();
        $this->assertNull(session('authToken'));
        $this->postJson('/api/login', ['email' => $other->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current', 'expected_email' => $other->email])->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->postJson('/api/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
        $chosen = $this->postJson('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current', 'expected_email' => $other->email])->assertOk();
        $target = $chosen->json('redirect_url');
        $this->assertStringStartsWith(rtrim(config('oidc.issuer'), '/').'/oauth/authorize?', $target);
        parse_str(parse_url($target, PHP_URL_QUERY), $resumed);
        $this->assertSame($query, $resumed);
        $this->get($target)->assertOk()->assertSee($other->email)->assertSee('Review what');
        $this->assertAuthenticatedAs($other);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        $this->assertDatabaseHas('oauth_auth_codes', ['user_id' => $other->id, 'nonce' => $query['nonce']]);
    }

    public function test_authenticated_but_unassigned_hint_never_gets_an_authorization_code(): void
    {
        [$user, , $query] = $this->fixture();
        $other = User::factory()->create();
        $this->actingAs($user)->get($this->url($query));
        $token = $this->choiceToken();
        $this->postJson('/api/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
        $chosen = $this->postJson('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current', 'expected_email' => $other->email])->assertOk();
        $this->get($chosen->json('redirect_url'))->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_choice_is_bound_to_its_authenticated_identity_and_expires(): void
    {
        [$user, , $query] = $this->fixture();
        $this->actingAs($user)->get($this->url($query));
        $token = $this->choiceToken();
        $this->withSession([SelectOAuthAccount::PENDING => [
            $token => [...session(SelectOAuthAccount::PENDING)[$token], 'expires_at' => time() - 1],
        ]]);
        $this->post('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current'])->assertStatus(410);
        $this->get($this->url($query));
        $token = $this->choiceToken();
        $other = User::factory()->create();
        $this->actingAs($other)->post('/oauth/select-account', ['choice_token' => $token, 'choice' => 'current'])->assertStatus(419);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_login_still_requires_reauthentication_and_consent_is_not_account_selection(): void
    {
        [$user, , $query] = $this->fixture('login');
        $this->actingAs($user)->get($this->url($query))->assertRedirect('/login');
        $this->assertGuest('web');
        $this->actingAs($user)->get($this->url($query))->assertOk()->assertSee('Review what');
        $this->actingAs($user)->get($this->url([...$query, 'prompt' => 'consent']))->assertOk()
            ->assertSee('Review what');
    }

    public static function invalidPrompts(): array
    {
        return array_map(fn ($prompt) => [$prompt], ['', 'unsupported', 'none consent', 'login select_account', 'select_account consent', ['login']]);
    }

    #[DataProvider('invalidPrompts')]
    public function test_unsupported_prompts_fail_without_issuing_credentials(mixed $prompt): void
    {
        [$user, , $query] = $this->fixture();
        $this->actingAs($user)->get($this->url([...$query, 'prompt' => $prompt]))
            ->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }
}
