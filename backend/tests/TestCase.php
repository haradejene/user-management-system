<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        // actingAs bypasses the real Login event; emulate its session stamp.
        $this->withHeader('Origin', 'http://localhost:3000');
        $this->withSession(['auth_session_version' => (int) $user->session_version]);

        return parent::actingAs($user, $guard);
    }
}
