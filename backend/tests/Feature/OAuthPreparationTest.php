<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_endpoints_are_exposed_without_oidc_endpoints(): void
    {
        $this->get('/oauth/authorize')->assertStatus(400);
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])->assertStatus(400);
        $this->postJson('/oauth/clients', ['name' => 'Unapproved client'])->assertNotFound();
        $this->getJson('/.well-known/openid-configuration')->assertNotFound();
    }
}
