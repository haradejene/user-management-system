<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OAuthPreparationTest extends TestCase
{
    public function test_configuration_does_not_expose_oauth_or_client_registration_routes(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertFalse(str_starts_with($route->getName() ?? '', 'passport.'));
        }

        $this->getJson('/oauth/authorize')->assertNotFound();
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])->assertNotFound();
        $this->postJson('/oauth/clients', ['name' => 'Unapproved client'])->assertNotFound();
        $this->getJson('/.well-known/openid-configuration')->assertNotFound();
    }
}
