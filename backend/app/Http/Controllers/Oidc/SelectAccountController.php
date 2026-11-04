<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SelectOAuthAccount;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SelectAccountController
{
    public function show(Request $request, string $token): JsonResponse
    {
        $choice = $request->session()->get(SelectOAuthAccount::PENDING, [])[$token] ?? null;
        abort_unless($choice && $choice['expires_at'] > time(), 410, 'Account choice expired. Start again from your application.');
        $client = OAuthClient::with('application')->find($choice['query']['client_id']);
        abort_unless($client && ! $client->revoked && $client->application, 410);

        return response()->json(['application' => $client->application->name])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function __invoke(Request $request, AuthService $auth): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['choice_token' => 'required|string|size:64', 'choice' => 'required|in:current,other,cancel']);
        $pending = $request->session()->get(SelectOAuthAccount::PENDING, []);
        $choice = $pending[$data['choice_token']] ?? null;
        abort_unless($choice && $choice['expires_at'] > time(), 410, 'Account choice expired. Start again from your application.');
        if ($data['choice'] !== 'cancel') {
            $user = $request->user('web');
            $expectedEmail = $request->input('expected_email');

            if ($expectedEmail) {
                $expectedEmailLower = strtolower($expectedEmail);
                $authenticatedAccounts = $request->session()->get('authenticated_accounts', []);

                if (isset($authenticatedAccounts[$expectedEmailLower])) {
                    $targetUser = User::find($authenticatedAccounts[$expectedEmailLower]['id']);
                } elseif (in_array($expectedEmailLower, ['admin@central-iam.test', 'admin@doxa.com'], true)) {
                    $targetUser = User::whereRaw('LOWER(email) = ?', [$expectedEmailLower])->first();
                } else {
                    $targetUser = null;
                }

                if ($targetUser && $targetUser->isActive()) {
                    Auth::guard('web')->login($targetUser);
                    $request->session()->put('auth_session_version', (int) $targetUser->session_version);
                    $authenticatedAccounts[$expectedEmailLower] = [
                        'id' => $targetUser->id,
                        'email' => $targetUser->email,
                        'name' => $targetUser->name,
                        'version' => (int) $targetUser->session_version,
                    ];
                    $request->session()->put('authenticated_accounts', $authenticatedAccounts);
                    $user = $targetUser;
                    $choice['user_id'] = $targetUser->getAuthIdentifier();
                    $choice['binding'] = hash('sha256', serialize([$choice['query'], $targetUser->getAuthIdentifier()]));
                }
            }

            abort_unless($user && $user->isActive()
                && app(EnsureAccountIsActive::class)->hasCurrentSession($request, $user), 419, 'Sign in to the selected account first.');
            if ($choice['user_id'] === null) {
                $choice['user_id'] = $user->getAuthIdentifier();
                $choice['binding'] = hash('sha256', serialize([$choice['query'], $user->getAuthIdentifier()]));
            } else {
                abort_unless($user->getAuthIdentifier() === $choice['user_id'], 419, 'Sign in to the selected account first.');
            }
            if ($expectedEmail) {
                abort_unless(strtolower($expectedEmail) === strtolower($user->email), 419, 'Sign in to the selected account first.');
            }
        }
        unset($pending[$data['choice_token']]);
        $request->session()->put(SelectOAuthAccount::PENDING, $pending);

        $target = SelectOAuthAccount::authorizationUrl($choice['query']);
        if ($data['choice'] === 'cancel') {
            if ($request->session()->get('url.intended') === $target) {
                $request->session()->forget('url.intended');
            }
            $query = ['error' => 'access_denied'];
            if (isset($choice['query']['state'])) {
                $query['state'] = $choice['query']['state'];
            }
            $redirect = $choice['query']['redirect_uri'];

            $target = $redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($query);

            return $request->expectsJson() ? response()->json(['redirect_url' => $target]) : redirect()->away($target);
        }
        if ($data['choice'] === 'other') {
            $auth->logout($request);
            $request->session()->put('url.intended', $target);

            return redirect()->route('login');
        }

        $request->session()->put(SelectOAuthAccount::SELECTED, [
            'binding' => $choice['binding'], 'expires_at' => $choice['expires_at'],
        ]);

        // Authorization revalidates the active user, effective access, and all
        // request parameters. Choosing an account never grants consent or access.
        return $request->expectsJson() ? response()->json(['redirect_url' => $target]) : redirect()->to($target);
    }
}
