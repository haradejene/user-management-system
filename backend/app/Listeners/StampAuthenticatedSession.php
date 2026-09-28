<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

class StampAuthenticatedSession
{
    public function handle(Login $event): void
    {
        if ($event->guard === 'web') {
            // Use the credential-validated snapshot, never a later refreshed version.
            session()->put('auth_session_version', (int) $event->user->session_version);
        }
    }
}
