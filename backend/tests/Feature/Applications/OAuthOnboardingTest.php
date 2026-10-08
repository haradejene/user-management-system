<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\User;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OAuthOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_updates_are_exact_scoped_authorized_and_conflict_checked(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $app = Application::factory()->create();
        $other = Application::factory()->create();
        $client = app(OAuthClientService::class)->create($app, ['name' => 'Server', 'confidential' => true, 'redirect_uris' => ['https://example.test/callback']]);
        $url = '/api/admin/applications/'.$app->public_id.'/oauth-clients/'.$client->id.'/redirect-uris';
        $payload = ['redirect_uris' => ['https://example.test/callback?Case=Value%2F'], 'updated_at' => $client->updated_at->toISOString()];
        $this->patchJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->patchJson($url, $payload)->assertForbidden();
        $this->actingAs($admin)->patchJson(str_replace($app->public_id, $other->public_id, $url), $payload)->assertNotFound();
        foreach ([['https://example.test/*'], ['https://example.test/callback#fragment'], ['https://example.test/callback', 'https://example.test/callback'], [' https://example.test/callback'], ['invalid']] as $uris) {
            $this->patchJson($url, ['redirect_uris' => $uris, 'updated_at' => $payload['updated_at']])->assertUnprocessable();
        }
        $saved = $this->patchJson($url, $payload)->assertOk()->assertJsonPath('data.redirect_uris', $payload['redirect_uris'])->assertJsonPath('data.pkce_required', true)->assertJsonPath('data.pkce_method', 'S256')->assertJsonMissingPath('data.secret')->assertJsonMissingPath('client_secret');
        $this->patchJson($url, $payload)->assertConflict();
        $this->patchJson($url, ['redirect_uris' => ['https://example.test/next'], 'updated_at' => $saved->json('data.updated_at')])->assertOk();
        $client->refresh();
        $client->update(['revoked' => true]);
        $this->patchJson($url, ['redirect_uris' => $payload['redirect_uris'], 'updated_at' => $client->updated_at->toISOString()])->assertConflict();
        $history = '/api/admin/applications/'.$app->public_id.'/history';
        $this->getJson($history)->assertOk()->assertJsonFragment(['action' => 'oauth_client.redirects_updated']);
        $this->actingAs(User::factory()->create())->getJson($history)->assertForbidden();
    }

    public function test_inactive_suspended_deleted_and_nonexistent_users_cannot_receive_access(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $app = Application::factory()->create();
        $this->actingAs($admin);
        foreach (['inactive', 'suspended'] as $status) {
            $user = User::factory()->create(['status' => $status]);
            $this->postJson('/api/admin/users/'.$user->public_id.'/applications', ['application_id' => $app->public_id])->assertUnprocessable()->assertJsonValidationErrors('user');
        }
        $user = User::factory()->create();
        $user->delete();
        foreach ([$user->public_id, (string) Str::uuid()] as $id) {
            $this->postJson('/api/admin/users/'.$id.'/applications', ['application_id' => $app->public_id])->assertNotFound();
        }
    }
}
