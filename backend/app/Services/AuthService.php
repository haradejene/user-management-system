<?php

namespace App\Services;

use App\Enums\AccountStatus;
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
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if ($user->status !== AccountStatus::Active) {
            $this->logout($request);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        return $user;
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
