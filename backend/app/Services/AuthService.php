<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Events\IamActivityOccurred;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /** @param array{name: string, email: string, password: string} $attributes */
    public function register(array $attributes, Request $request): User
    {
        $user = DB::transaction(function () use ($attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => AccountStatus::Active,
            ]);

            IamActivityOccurred::dispatch('auth.registered', $user, actor: $user);

            event(new Registered($user));

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $user;
    }

    /** @param array{email: string, password: string, remember?: bool} $credentials */
    public function login(array $credentials, Request $request): User
    {
        $authenticated = Auth::guard('web')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], $credentials['remember'] ?? false);

        if (! $authenticated) {
            IamActivityOccurred::dispatch('auth.login_failed', metadata: ['reason' => 'invalid_credentials']);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if ($user->status !== AccountStatus::Active) {
            $this->clearSession($request);
            IamActivityOccurred::dispatch('auth.login_failed', $user, ['reason' => 'inactive_account']);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();
        IamActivityOccurred::dispatch('auth.login', $user, actor: $user);

        return $user;
    }

    public function logout(Request $request): void
    {
        $user = Auth::guard('web')->user();
        IamActivityOccurred::dispatch('auth.logout', $user, actor: $user);
        $this->clearSession($request);
    }

    private function clearSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
