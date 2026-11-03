<?php

namespace App\Http\Middleware;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\OAuthClient;
use App\Services\ApplicationAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SelectOAuthAccount
{
    public function __construct(private readonly ApplicationAccessService $access) {}

    public const PENDING = 'oauth_account_choices';

    public const SELECTED = 'oauth_selected_account';

    public static function binding(Request $request): string
    {
        return hash('sha256', serialize([$request->query(), $request->user('web')?->getAuthIdentifier()]));
    }

    public static function authorizationUrl(array $query): string
    {
        return rtrim(config('oidc.issuer') ?? config('app.url'), '/')
            .route('passport.authorizations.authorize', $query, false);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/authorize') || ! $request->isMethod('GET')
            || $request->query('prompt') !== 'select_account') {
            return $next($request);
        }

        $client = OAuthClient::with('application')->findOrFail($request->query('client_id'));
        if ($request->user('web') && ! $this->access->isAllowed($request->user('web'), $client->application)) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        $selected = $request->session()->pull(self::SELECTED);
        if (is_array($selected) && ($selected['expires_at'] ?? 0) > time()
            && ($selected['binding'] ?? null) === self::binding($request)) {
            return $next($request);
        }

        return self::offer($request, $request->query());
    }

    public static function offer(Request $request, array $query): Response
    {
        // Only the current authenticated identity is available in this session.
        // Bounded, expiring, server-side choices carry the original OIDC request.
        $pending = array_filter($request->session()->get(self::PENDING, []),
            fn (array $choice): bool => $choice['expires_at'] > time());
        $token = Str::random(64);
        $pending[$token] = [
            'query' => $query,
            'user_id' => $request->user('web')?->getAuthIdentifier(),
            'binding' => hash('sha256', serialize([$query, $request->user('web')?->getAuthIdentifier()])),
            'expires_at' => time() + 600,
        ];
        $request->session()->put(self::PENDING, array_slice($pending, -8, null, true));

        // Only the frontend origin can read its remembered-account hints. Keep
        // every OIDC parameter server-side, behind this session-bound token.
        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

        return redirect()->away($frontend.'/login?account_choice='.$token)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public static function authenticated(Request $request): void
    {
        // Called only after successful credentials/session regeneration. Hints
        // never call this method or change the authenticated identity.
        $pending = $request->session()->get(self::PENDING, []);
        foreach ($pending as &$choice) {
            $choice['user_id'] = $request->user('web')->getAuthIdentifier();
            $choice['binding'] = hash('sha256', serialize([$choice['query'], $choice['user_id']]));
        }
        unset($choice);
        $request->session()->put(self::PENDING, $pending);
    }
}
