# Doxa Laravel SDK v1 — Milestone 1

A reusable OIDC relying-party package for Laravel 10, 11 and 12 on PHP 8.2+. Doxa IAM owns central authentication and application access. This package implements Doxa Universal SDK Contract v1.0 and returns a validated, immutable `DoxaIdentity`. The same SDK v1 package and public API work across these Laravel versions; integration does not require upgrading the host's Laravel version. Laravel 9 and PHP below 8.2 are unsupported.

```text
Continue with Doxa
        ↓
Doxa SDK → Doxa IAM → authorization code
        ↓
Validated DoxaIdentity
        ↓
Host application lookup(issuer, subject)
        ↓
Host local eligibility policy → host local session
```

## Installation

This package is currently developed at `packages/doxa-laravel`; it is not yet published to a Composer registry. From a host application's Composer manifest, add a path repository pointing to this directory, then require the package:

```json
{
    "repositories": [{"type": "path", "url": "../path/to/doxa-iam/packages/doxa-laravel"}],
    "require": {"doxa/laravel-sdk": "@dev"}
}
```

Run `composer update doxa/laravel-sdk --with-dependencies` in that host. No Passport or Sanctum installation is required by the SDK. Runtime dependencies are declared in the package manifest; the parent IAM Composer installation is not used.

Laravel discovers `Doxa\Laravel\DoxaServiceProvider` automatically. If package discovery is disabled, add it to the `providers` array in `config/app.php` on Laravel 10, or to `bootstrap/providers.php` on Laravel 11/12. Existing applications with a customized bootstrap should use their provider registration mechanism. Publish configuration with:

```shell
php artisan vendor:publish --tag=doxa-config
```

## Configuration

Set `DOXA_ISSUER`, `DOXA_CLIENT_ID`, `DOXA_REDIRECT_URI` and, for a confidential client, `DOXA_CLIENT_SECRET` in protected server configuration. Obtain the registration outside this package. No production URLs are built in.

| Setting | Default / behavior |
| --- | --- |
| `issuer` | Required exact trusted issuer; HTTPS, no query/fragment/credentials. |
| `client_id` | Required registered OAuth client ID, not IAM application ID. |
| `client_secret` | Absent for public clients; protected server-side for confidential clients. |
| `redirect_uri` | Required exact registered HTTPS callback, no fragment. |
| `scopes` | `['openid']`; request `profile` and/or `email` explicitly if needed. No implicit scopes; `offline_access` is rejected. |
| `pkce_required` | `true`; disabling PKCE fails configuration validation. S256 is mandatory for every client. |
| `timeout` | 10 seconds, configurable 1–60. Applied to network operations. |
| `discovery_cache_ttl` / `jwks_cache_ttl` | 300 seconds, configurable 1–3600; provider cache directives may shorten this. |
| `client_auth_method` | `none` without a secret; `client_secret_basic` with a secret. Explicit confidential `client_secret_post` also supported when advertised. |
| `cache_store` | Host's default store, or a named persistent store. |
| `userinfo_enabled` | `false`. When true, retrieve matching-sub UserInfo during callback before returning identity. |

Discovery originates only from the issuer. Endpoints come from validated discovery; endpoint overrides are not provided. Other endpoint origins are permitted only when advertised by validated discovery and use HTTPS. All network redirects are disabled; no certificate-verification bypass exists. This milestone requires HTTPS even for local development.

Use a stable host `APP_KEY` shared by its workers to encrypt pending authorization transactions. Use server-side sessions, Secure/HttpOnly cookies, and SameSite `lax` (or Secure `none` when deliberately needed). The provider rejects browser-cookie session storage and insecure cookie settings. Routes must run in the host's `web` middleware group. Session-ID changes during an outstanding attempt invalidate its browser binding; start a new login.

### Storage and deployment

Use shared **Redis or database cache** for deployments with multiple application servers. Database cache needs the host's ordinary Laravel cache table; the SDK supplies no IAM migration or package-specific table. File cache is supported for independent workers on one server with a common local directory; do not assume network filesystems provide reliable atomic file semantics. Array, null and failover caches are rejected for transaction storage.

Transactions expire after 10 minutes. Pending records contain encrypted state, nonce, verifier and immutable provider/client/redirect/scope/browser context. Atomic cache `add` persists an irreversible processing marker before exchange; it is not a lease. Pending secrets are deleted after claim, and only a nonsensitive terminal marker remains for a bounded period (expiry plus 60 seconds). A crashed worker cannot admit another exchange. An unavailable/evicted store fails closed; a new login is required. Do not flush shared transaction storage during active login traffic.

JWKS keys are issuer/URL isolated. An unknown key in a valid cache permits one refresh per validation, rate-limited to one per context per 30 seconds. Cold/expired fetches also use an atomic cooldown of up to five seconds. During concurrent cold fetches or a cooldown, another request can fail closed; it must start a new login. No stale-cache validation bypass or key-rotation overlap is promised.

## Routes and usage

The SDK registers no routes or authentication guard. Define host routes, for example in `routes/web.php`:

```php
use App\Http\Controllers\DoxaLoginController;
use Doxa\Laravel\Http\SecureCallbackResponse;
use Illuminate\Support\Facades\Route;

Route::get('/login/doxa', [DoxaLoginController::class, 'login']);
Route::get('/login/doxa/callback', [DoxaLoginController::class, 'callback'])
    ->middleware(SecureCallbackResponse::class);
```

The optional package middleware sets no-store/no-referrer headers and converts uncaught SDK errors to safe category-only 400 JSON responses. It does not authenticate a local user. Use it on the callback, or provide equivalent protection in the host's own middleware/error handler.

```php
use Doxa\Laravel\DoxaClient;
use Illuminate\Http\Request;

public function login(DoxaClient $doxa)
{
    return $doxa->beginLogin();
}

public function callback(Request $request, DoxaClient $doxa)
{
    $identity = $doxa->handleCallback($request);

    // Host application only:
    // lookup local user using $identity->issuer() + $identity->subject()
    // enforce local account policy
    // establish the host's local session if allowed

    return redirect('/'); // A clean URL, without the callback's code/state.
}
```

`beginLogin()` returns a Laravel redirect. `handleCallback()` returns `DoxaIdentity`; it never calls `Auth::login`, provisions accounts or establishes the application's authenticated session. Optional `discover()` returns typed validated metadata. Protocol services are internal infrastructure, not alternate login APIs. Store creation requires an SDK-generated transaction, and authorization checks provider metadata against trusted discovery. Exchange and validation services require the same transaction store that issued the claimed transaction object; caller-constructed, cloned, expired or finished transactions fail closed. `TransactionStore` implementations must enforce claimed-object provenance and atomic single-use exchange through `assertClaimed()` and `consume()`. Direct exchange is terminal even after an uncertain transport outcome.

The callback parser reads the original query string, detects duplicate code/state/error (including encoded keys/array forms), and scrubs query data from the request before subsequent host diagnostics. Missing or substituted state/browser context never exchanges a code. Once claimed, provider denial, token errors, uncertain network outcomes and validation failures are terminal. Do not retry a code exchange; start a new login instead.

## Identity and UserInfo

Use `issuer()` and `subject()` together as the permanent identity key. Doxa currently issues the central public UUID as `sub`; the SDK preserves it as an opaque string. Never map permanently by email, including verified email. The host owns mapping uniqueness, disabled-account policy, linking/provisioning and local authorization.

Optional accessors: `email()`, `emailVerified()`, `name()`, `givenName()`, `familyName()`, `picture()`. Unavailable fields are null; `emailVerified() === false` is preserved. Explicit JSON serialization contains only these fields plus issuer/subject. Debug output is redacted. No raw claims, internal IDs, roles, memberships, tokens or credentials are exposed.

Identity construction requires successful token validation. RS256 signature, trusted RSA JWKS (minimum 2048 bits), exact issuer, audience/authorized party, required expiry/issued-at, optional not-before, nonce and nonempty subject are mandatory. **Decoded JWT is not validated identity.** This version uses **zero clock skew**, within the contract's maximum 60 seconds; synchronize clocks. No skip-validation switch exists.

With `userinfo_enabled=true`, access-token provenance must include `openid`, the token is sent only as a bearer header, and UserInfo subject must match validated ID-token subject. Supplied allowlisted profile/email values replace earlier attributes; missing values preserve ID-token values. Issuer/subject never change. UserInfo failure fails the callback safely, rather than silently accepting enrichment or reopening the transaction. Default login skips UserInfo entirely. No access/refresh tokens are returned or persisted; refresh tokens received from IAM are discarded.

## Errors and host security

Catch typed exceptions under `Doxa\Laravel\Exceptions`; their `category` is safe protocol metadata. Categories distinguish configuration/discovery/capability, state/transaction expiry/replay, provider denial, exchange, signature/issuer/audience/nonce/time validation and UserInfo failures. Provider bodies and low-level exceptions are not chained into SDK exceptions. Payload validation helpers return before typed exceptions are constructed, avoiding unnecessary claim/payload arguments in traces. Legitimate sensitive public parameters use PHP sensitive-parameter redaction; its wrappers retain normal PHP behavior. `access_denied` does not prove whether a person clicked deny or IAM policy denied access.

The isolated Guzzle client emits no Laravel HTTP logging events and installs no logging or retry middleware. Config/token/identity diagnostic dumps are redacted. The host must also exclude callback query strings, Authorization headers and credential-bearing configuration from web-server/proxy logs, tracing, error pages and analytics. Protect `.env` and configuration caches; do not publish `config:show doxa` output or dump application configuration. Set production `APP_DEBUG=false`. The SDK cannot control instrumentation outside its transport/request boundary.

## Intentionally outside v1

No local users, account activation/linking, employees, company membership, roles, permissions, business authorization, automatic local login, IAM administration, refresh management, federated logout, revocation, invitations, MFA, signing-key rotation or other-language SDKs. Existing host sessions are not terminated automatically by IAM lifecycle changes. Those policies/features require separate milestones.

## Package development

From this directory:

```shell
composer install
composer test
composer analyse
composer check-format
composer validate --strict
```

Tests include package-level protocol integration, a Laravel Testbench route round trip and independent-process callback races using shared file and SQLite database caches. They do not contact production IAM, modify its application, or depend on `backend/vendor`. See `verification.md` for recorded results and remaining deployment checks.

### Framework compatibility matrix

The checked-in `composer.lock` is only the Laravel 12 development baseline. It does not prove Laravel 10/11 compatibility. `tools/compatibility.php` derives a separate root manifest and lock under ignored `.compatibility/` for each target. Composer selects the compatible Testbench patch, including one that retains Laravel 10.50.2. Each test profile runs Composer validation, the complete PHPUnit suite, PHPStan level 5 and Pint.

| Profile | Laravel | Testbench | PHPUnit | Purpose |
| --- | --- | --- | --- | --- |
| `laravel10` | 10.50.2 | 8.x | 10.5.x | Exact first integration target; Guzzle 7.13.1, HttpFoundation 6.4 |
| `laravel11` | 11.x | 9.x | 11.5.x | Independently resolved Laravel 11 |
| `laravel12` | 12.69.3 | 10.x | 11.5.x | Independently resolved Laravel 12 |
| `floors` | 10.50.2 | 8.x | 10.5.x | Guzzle 7.8.2 and Firebase JWT 7.1.0 with HttpFoundation 6.4 |
| `hrm` | 10.50.2 | None | None | Solver-only synthetic consumer with Sanctum 3.3.3 and Guzzle 7.13.1 |

Run from the package directory with PHP 8.2 and Composer 2.10+:

```shell
php tools/compatibility.php laravel10 --run --historical-advisories
php tools/compatibility.php laravel11 --run --historical-advisories
php tools/compatibility.php laravel12 --run
php tools/compatibility.php floors --run --historical-advisories
php tools/compatibility.php hrm --run --historical-advisories
```

Current Composer advisories block the historical Laravel 10/11 targets and older Guzzle versions. `--historical-advisories` explicitly opts into a command-only advisory-blocking exception for isolated compatibility tests. It does not suppress audit reporting, change the package's default Composer policy, or establish that those host dependencies are free of vulnerabilities. Do not use it as an application deployment recommendation. See `verification.md` for the recorded advisory results.

Profiles share this checkout's `vendor/` because existing subprocess security fixtures bootstrap that directory. Run them sequentially, or use a separate checkout per parallel CI job. Every profile resolves independently of the checked-in Laravel 12 lock. CI can invoke these same commands; no repository-root CI configuration is installed by this package. All recorded runs use real PHP 8.2.12; the fixture's Composer platform is also pinned to 8.2.12. Other PHP runtimes need their own test runs before being reported as verified.

Without `--run`, the tool only generates the selected manifest for manual Composer commands using `COMPOSER=.compatibility/composer-<profile>.json`. Generated manifests, locks and JUnit reports remain local. To return to the checked-in Laravel 12 environment, clear any manually set `COMPOSER` variable and run `composer install`, followed by the normal package checks above. Neither matrix generation nor execution modifies the HRM repository. The `hrm` profile is not the complete HRM manifest or lockfile.
