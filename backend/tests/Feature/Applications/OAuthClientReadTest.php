<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OAuthClientReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reads_only_safe_metadata_for_clients_in_the_selected_application(): void
    {
        [$admin, $application, $client] = $this->fixture();
        $otherApplication = Application::factory()->create();
        $otherClient = $this->client($otherApplication);
        $expected = [
            'id' => $client->id, 'name' => $client->name, 'application_id' => $application->public_id,
            'redirect_uris' => $client->redirect_uris, 'confidential' => true,
            'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
            'pkce_required' => true, 'pkce_method' => 'S256', 'allowed_scopes' => Passport::scopeIds(),
            'issuer' => config('oidc.issuer') ?? config('app.url'),
            'discovery_url' => rtrim(config('oidc.issuer') ?? config('app.url'), '/').'/.well-known/openid-configuration',
            'created_at' => $client->created_at->toISOString(), 'updated_at' => $client->updated_at->toISOString(),
        ];
        $list = $this->actingAs($admin)->getJson($this->url($application))->assertOk()->assertJsonCount(1, 'data');
        $show = $this->getJson($this->url($application).'/'.$client->id)->assertOk();
        $this->assertSame($expected, $list->json('data.0'));
        $this->assertSame($expected, $show->json('data'));
        foreach ([$list, $show] as $response) {
            $this->assertStringNotContainsString($otherClient->id, $response->getContent());
            $this->assertStringNotContainsString($client->secret, $response->getContent());
            $this->assertStringNotContainsString($client->plainSecret, $response->getContent());
            foreach (['secret', 'client_secret', 'access_token', 'refresh_token', 'authorization_code', 'plainSecret'] as $field) {
                $this->assertArrayNotHasKey($field, $response->json($response === $list ? 'data.0' : 'data'));
            }
        }
        $this->assertNotSame((string) $application->id, $show->json('data.application_id'));
    }

    public function test_cross_application_unknown_and_malformed_client_identifiers_return_not_found(): void
    {
        [$admin, $application, $client] = $this->fixture();
        $other = Application::factory()->create();
        foreach ([$client->id, (string) Str::uuid(), 'hello', '123'] as $id) {
            $response = $this->actingAs($admin)->getJson($this->url($other).'/'.$id)->assertNotFound();
            $this->assertStringNotContainsString($client->secret, $response->getContent());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        }
        $this->assertSame($application->id, $client->fresh()->application_id);
    }

    public function test_non_admin_and_unauthenticated_users_cannot_read_clients(): void
    {
        [, $application, $client] = $this->fixture();
        foreach ([$this->url($application), $this->url($application).'/'.$client->id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create());
        foreach ([$this->url($application), $this->url($application).'/'.$client->id] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_inactive_admin_cannot_read_clients(): void
    {
        [$admin, $application, $client] = $this->fixture();
        $admin->update(['status' => 'inactive']);
        foreach ([$this->url($application), $this->url($application).'/'.$client->id] as $url) {
            $this->actingAs($admin)->getJson($url)->assertForbidden();
        }
    }

    public function test_revoked_clients_and_inactive_parents_remain_visible_for_admin_inspection(): void
    {
        [$admin, $application, $client] = $this->fixture();
        app(OAuthClientService::class)->revoke($application, $client);
        $application->update(['status' => 'inactive']);
        $this->actingAs($admin)->getJson($this->url($application))->assertOk()->assertJsonPath('data.0.revoked', true);
        $this->getJson($this->url($application).'/'.$client->id)->assertOk()->assertJsonPath('data.revoked', true);
        $this->assertSame($application->id, $client->fresh()->application_id);
    }

    public function test_deleted_parent_applications_cannot_be_read(): void
    {
        [$admin, $application, $client] = $this->fixture();
        $application->delete();
        $this->actingAs($admin)->getJson($this->url($application))->assertNotFound();
        $this->getJson($this->url($application).'/'.$client->id)->assertNotFound();
    }

    public function test_client_list_pagination_is_scoped_and_stable(): void
    {
        [$admin, $application, $client] = $this->fixture();
        $ids = [$client->id];
        for ($index = 0; $index < 4; $index++) {
            $ids[] = $this->client($application)->id;
        }
        $other = $this->client(Application::factory()->create());
        $actual = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->actingAs($admin)->getJson($this->url($application).'?per_page=2&page='.$page)
                ->assertOk()->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
            $this->assertStringNotContainsString($other->id, $response->getContent());
            $actual = [...$actual, ...array_column($response->json('data'), 'id')];
        }
        $this->assertEqualsCanonicalizing($ids, $actual);
        $this->assertCount(5, array_unique($actual));
    }

    public static function invalidPagination(): array
    {
        return [['per_page', '0'], ['per_page', '101'], ['per_page', 'hello'], ['page', '0'], ['page', '-1'], ['page', 'hello']];
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_is_rejected(string $field, string $value): void
    {
        [$admin, $application] = $this->fixture();
        $this->actingAs($admin)->getJson($this->url($application).'?'.$field.'='.$value)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_creation_returns_a_confidential_secret_once_but_reads_never_return_it(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $application = Application::factory()->create();
        $other = Application::factory()->create();
        $created = $this->actingAs($admin)->postJson($this->url($application), [
            'name' => 'Confidential client', 'redirect_uris' => ['https://client.example.test/callback'],
            'confidential' => true, 'application_id' => $other->id, 'secret' => 'submitted-secret',
        ])->assertCreated();
        $secret = $created->json('client_secret');
        $client = OAuthClient::findOrFail($created->json('data.id'));
        $this->assertIsString($secret);
        $this->assertTrue(Hash::check($secret, $client->secret));
        $this->assertSame($application->id, $client->application_id);
        $this->assertNotSame('submitted-secret', $secret);
        $this->assertArrayNotHasKey('secret', $created->json('data'));
        foreach ([$this->url($application), $this->url($application).'/'.$client->id] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($client->secret, $response->getContent());
            $response->assertJsonMissingPath('client_secret');
        }
        $public = $this->postJson($this->url($application), ['name' => 'Public client', 'redirect_uris' => ['https://public.example.test/callback'], 'confidential' => false])
            ->assertCreated()->assertJsonMissingPath('client_secret');
        $this->getJson($this->url($application).'/'.$public->json('data.id'))->assertOk()->assertJsonPath('data.confidential', false);
    }

    private function fixture(): array
    {
        $admin = User::factory()->systemAdmin()->create();
        $application = Application::factory()->create();

        return [$admin, $application, $this->client($application)];
    }

    private function client(Application $application): OAuthClient
    {
        return app(OAuthClientService::class)->create($application, ['name' => 'Admin read test', 'redirect_uris' => ['https://client.example.test/callback'], 'confidential' => true]);
    }

    private function url(Application $application): string
    {
        return '/api/admin/applications/'.$application->public_id.'/oauth-clients';
    }
}
