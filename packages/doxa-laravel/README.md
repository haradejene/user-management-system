# Doxa Laravel SDK v1 — Milestone 1

A reusable OIDC relying-party package for Laravel 10, 11 and 12 on PHP 8.2+. Doxa IAM owns central authentication and application access. This package implements Doxa Universal SDK Contract v1.1 and returns a validated, immutable `DoxaIdentity`. The same SDK v1 package and public API work across these Laravel versions; integration does not require upgrading the host's Laravel version. Laravel 9 and PHP below 8.2 are unsupported.

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

### Authorization options and forced reauthentication

`beginLogin(?AuthorizationOptions $options = null)` accepts an immutable, allowlisted value supplied by trusted server-side application code. Existing `$doxa->beginLogin()` calls continue unchanged: no `prompt` is sent, and normal Doxa SSO behavior applies. Explicit `null` or `new AuthorizationOptions()` also leaves the request unchanged. The SDK continues to construct the complete authorization request.

To explicitly request provider reauthentication:

```php
use Doxa\Laravel\Authorization\AuthorizationOptions;
use Doxa\Laravel\DoxaClient;

public function reauthenticate(DoxaClient $doxa)
{
    return $doxa->beginLogin(AuthorizationOptions::reauthenticate());
    // Equivalent: $doxa->beginLogin(new AuthorizationOptions(prompt: 'login'));
}
```

This adds exactly one `prompt=login` to the outgoing request, using the SDK's existing RFC 3986 encoder. It requests a fresh provider authentication interaction instead of ordinary reuse of an SSO session. A host workflow that needs reauthentication should choose this option explicitly on the server. This SDK capability does not implement account linking or change any host identity/local-account policy.

**`prompt=login` is not account selection.** [OIDC Core](https://openid.net/specs/openid-connect-core-1_0.html#AuthRequest) distinguishes reauthentication (`login`) from account selection (`select_account`). The current SDK exposes only `login`; future `select_account` requires IAM/provider support and an explicit contract/SDK extension. Reauthentication is not a guarantee of switching accounts, an `auth_time` claim, or a particular authentication method. Existing ID-token validation is unchanged.

The only accepted prompt values are absence (`null`) and the exact string `login`. Unsupported values such as `select_account`, `none`, `consent`, combined prompts, arbitrary strings, empty strings and whitespace/case variants throw `AuthorizationException` with safe category `unsupported_authorization_option` before discovery, transaction creation or redirect. The error never echoes the rejected input or chains a low-level exception. Incorrect argument types/unknown named parameters are programming errors rejected by PHP before login side effects.

There is no arbitrary parameter map, `fromArray` request adapter or security-parameter override. Never construct options from `$request->query()` or callback data; choose an explicit option for the trusted server-side route/workflow. Browser query parameters are not read as options and cannot override the SDK's selected prompt, state, nonce, client, redirect URI, scopes or S256. Callback `prompt` is ignored and scrubbed with other callback query data. Options affect only the outgoing request; they are not stored as transaction fields or sent to the token endpoint, and do not change binding, expiry, claims/provenance, replay handling, exchange, ID-token/JWKS or UserInfo validation.

### Current application-access checks

IAM offers the client-bound `GET /oauth/application-access` resource. It needs a valid user-delegated access credential with `openid`; it does not need administrator permissions or accept identity/application selectors. Universal contract v1.2 specifies optional SDK support.

`$decision = $doxa->checkApplicationAccess($context)` now performs the fixed issuer-relative `GET /oauth/application-access`. Only a strictly validated HTTP 200 minimal `{"effective":true}` object returns `ApplicationAccessDecision` with `effective=true`. `ApplicationAccessException::category` distinguishes ambiguous `access_denied_or_insufficient_scope` (403), `credential_invalid` (401), `incompatible_context`, transient service failure, protocol failure and lifecycle/refresh failure. It never returns a fabricated negative decision or denial reason. There are no selectors, endpoint overrides, positive cache, automatic 401 refresh/replay or generic resource APIs. Issuer base-path deployments must route this fixed path explicitly.

The operation rechecks trusted host binding under operation name `check_application_access`, including pending-link initiator, transaction, target and deadline. Ordinary contexts need completed owner binding/proof; unbound ordinary contexts cannot call IAM. Pending-link contexts may perform only this check and its necessary internal refresh until the host-confirmed completion protocol below succeeds. Identity-only callbacks still discard credentials and require no managed configuration.

Access credentials outside `credentials.refresh_margin_seconds` (default 60, integer 0-60) are reused. Near-expiry credentials refresh lazily only for this operation, using validated discovery, one registered authentication method and mandatory rotation. The absolute context deadline never extends. Refresh HTTP dispatch is reachable only from a one-shot owner-validated invocation after an acknowledged durable dispatch-intent transition. A constructed attempt or persisted phase cannot authorize sending. No token getters are introduced; response bodies and credentials remain transient internal inputs, not exportable result properties.

There are no implicit refresh retries. Unknown refresh outcomes erase/fence credentials and require fresh authorization; store outages leave durable nonusable work for cleanup. Only the same already validated response may be committed/reconciled under its original fence. Concurrent callers wait up to 250 ms and reload, then reuse the committed pair or receive `credential_busy`. HTTP 401/403 retires the context; resource timeout/5xx permits a later explicit check. Each access GET has a versioned `checking` fence: interrupted/uncertain checks cannot be restored, and cleanup expires them. No database transaction spans HTTP. An already sent request cannot be recalled; a positive response is revalidated against live ownership/lifecycle before returning and is never continuing authorization.

The new operation currently requires the SDK `DatabaseCredentialStore`; custom Milestone A adapters do not automatically gain credential acquisition. No local session, identity or HRM mapping is created/renewed. Do not use cached identity/UserInfo as a current access decision. PostgreSQL and persistent SQLite remain the managed-store targets; MySQL/MariaDB is unsupported. See `verification.md` for actual execution evidence and deployment limitations.

### Managed credentials — Milestone A groundwork

Managed retention is explicit per authorization attempt. Existing `beginLogin()` and `handleCallback(): DoxaIdentity` calls remain unchanged and need no credential table, credential key or owner adapter. This package does not yet claim complete Universal Contract v1.3 managed-capability conformance.

Configure `doxa.credentials` only when using managed login:

```php
'credentials' => [
    'enabled' => true,
    'connection' => 'pgsql', // Host connection name; separate SDK connection/instrumentation.
    'table' => 'doxa_credential_contexts',
    'key' => env('DOXA_CREDENTIAL_KEY'),
    'key_id' => env('DOXA_CREDENTIAL_KEY_ID'),
    'lifetime_seconds' => 3600, // Finite host-approved absolute lifetime, 60–604800 seconds.
    'unbound_seconds' => 300,   // 1–600 seconds to finish ordinary association.
    'tombstone_seconds' => 3600,// 60–604800 seconds; bounded stale-operation protection.
],
```

Explicitly publish and review the migration, then run it through the host's normal deployment process:

```shell
php artisan vendor:publish --tag=doxa-credentials-migrations
php artisan migrate
```

The provider never migrates, creates credential records or starts cleanup jobs on boot. The migration honors the configured connection/table; keep these stable across installation and rollback. Laravel's resolved connection configuration, including database URLs, is retained. In-memory SQLite databases are rejected because they cannot preserve contexts across processes; SQLite tests use persistent files. `illuminate/database` is now a runtime dependency with the same Laravel 10/11/12 constraints. No Eloquent user model, guard, IAM database table or cross-database foreign key is required.

Supply a **dedicated random 32-byte credential key**, either raw or `base64:` encoded, through protected host configuration, plus a nonsecret key identifier (1–64 letters/digits/dots/underscores/hyphens). The Laravel adapter uses AES-256-CBC encryption with Laravel's integrity protection and a versioned JSON payload. There is **no APP_KEY fallback**. A host explicitly approving reuse of an existing Laravel key must deliberately configure that key here: compromise/rotation then affects both concerns. The key identifier must match on decrypt. Automatic key-ring fallback/rotation is not implemented; preserve keys for retained records or deliberately invalidate/delete them before changing keys. A host may replace `CredentialCipher` with an approved key-management adapter. Never print configuration/key/ciphertext or include them in diagnostics/backups without equivalent access controls.

Register a trusted `Doxa\Laravel\Contracts\CredentialHostBinding` implementation in the host container. No permissive default is provided. Its responsibilities are:

- `capture(Request, ManagedLoginPolicy)`: resolve authorized protected host data before redirect. For `pending_link`, return nonempty string `initiator`, `link`, `target` and an integer `deadline` no more than ten minutes ahead. The link must equal the policy reference. The target is a local host identifier, never an IAM selector. Keep values bounded JSON scalars/arrays, not models, services or credentials.
- `validate(Request, metadata, operation)`: reject stale/cancelled host transactions, wrong initiator/session/purpose/identity/target, ineligible accounts or unauthorized operations. Operations are `begin`, `callback`, `bind`, `delete` and `restore`. Metadata before callback contains `purpose`, `host`, `browser`; record metadata later contains that snapshot under `policy` plus exact `issuer`, `client`, `subject`, scopes and lifecycle. Account identifiers must include the host's tenant/model namespace where needed.
- `complete(Request, metadata)`: for ordinary binding only, resolve the **committed exact `(issuer, subject)` mapping**, enforce local eligibility and any authorized session-regeneration transition, then return `['account' => $localAccountKey, 'session' => hash('sha256', $request->session()->getId())]`. It must not create mappings, accept a browser-supplied account as proof or authenticate a local user. A supplied user ID/boolean is not trusted evidence.

Choose policy and authorization options in trusted controller/service code:

```php
use Doxa\Laravel\Credentials\ManagedLoginPolicy;

// Before redirect: server-selected retention, independent of AuthorizationOptions.
return $doxa->beginManagedLogin(ManagedLoginPolicy::ordinary());

// On the matching callback route:
$result = $doxa->handleManagedCallback($request);
$identity = $result->identity();
$context = $result->credentialContext();

// Host resolves its existing exact mapping, checks eligibility and establishes its session.
// Only after that mapping/session is committed, the configured adapter can verify it:
$context = $doxa->bindCredentialContext($context);
```

An identity-only handler cannot upgrade a transaction to managed retention; a managed transaction cannot silently fall back to identity-only. Mismatches fail before exchange. State/nonce/S256, browser binding, exact configuration, transaction expiry, claim provenance and one-time exchange remain mandatory. Older transactions without a managed policy remain identity-only. Custom transaction adapters must explicitly implement `ManagedTransactionStore`, preserve policy and implement `assertFinished`; ordinary adapters remain usable for identity-only login.

The callback validates identity and configured UserInfo before persistence, inserts an encrypted nonusable `staged` record, finalizes the existing authorization transaction, verifies its successful completion, and conditionally activates `awaiting_binding` or `pending_link`. Insertion/commit/finalization/activation failure returns no managed result, never retries the code and never falls back to identity-only. Staged crashes remain nonusable and expire through cleanup. Contexts/results reject PHP and JSON serialization and redact debug output. `DoxaIdentity` remains unchanged. Neither a context nor a managed result contains a raw token getter.

Binding returns a **replacement** opaque context. Persist only its `serverReference()` in the protected host association after successful return; replace the old unbound reference. The replacement contains a separately random completion proof, whose hash and immutable attempt ID are encrypted in a durable nonusable `binding` claim before host mapping validation. Activation requires that exact attempt, hash, version and live state; claim/mapping failures retire only that attempt. The proof preimage is released only after the active write is acknowledged. An active row alone never makes an old unbound reference usable: lost acknowledgement, unavailable compensation or worker death releases no proof and requires fresh authorization. Keep the complete replacement reference server-side and exclude it from logs. Storage recovery must not reconstruct a completed reference from its record ID/hash. Restoration also rechecks live trusted host ownership/session policy; storage or host-validation failure denies restoration. A known losing binder cannot compensate another attempt. Unreleased records remain inaccessible and expire through normal cleanup. The table schema is unchanged. Any active records from the earlier uncommitted implementation have no completion proof and fail closed; invalidate those test/pilot records and authorize again rather than synthesizing proof or converting an ID-only reference. Existing identity-only applications need no migration.

Opaque context IDs have 256 bits of randomness; completed references additionally carry an independent 256-bit release proof. `serverReference()` is **only** for a protected server-side host association, never browser cookies, public JSON, URLs, logs or request parameters. Restore through `$doxa->restoreCredentialContext($storedServerReference)`; it independently validates current ownership/session/issuer/client and lifecycle. `CredentialContext::fromServerReference()` constructs no authorization proof. Ordinary unbound contexts may only be bound/deleted; completed contexts cannot be transferred or rebound across accounts. Initial session regeneration is permitted only through the explicit host adapter's bind verification. Later regeneration requires retiring/re-authorizing the context in Milestone A.

For pending linking, trusted host code may start `ManagedLoginPolicy::pendingLink($protectedLinkReference)`. The adapter must resolve/recheck the authenticated administrator or equivalent initiator, initiating session, specific link transaction, immutable target and deadline. The verified identity remains separate in protected host pending-link storage. A host must not create a mapping from the callback result alone. The optional completion protocol below coordinates the SDK context with a host-owned linking transaction; it does not implement HRM mapping or confirmation UI.

### Host-confirmed pending-link completion

`$context = $doxa->completePendingLink($pendingContext)` returns a **replacement** completed reference. It accepts no account ID, boolean confirmation, supplied access decision or constructible proof object. The service registered as `CredentialHostBinding` must additionally implement `PendingLinkHostBinding`; existing adapters fail closed for this optional operation. `DatabaseCredentialStore` is required. No identity-only or ordinary binding API changes.

The host adapter is a security boundary. Implement its four methods using protected durable host records, never request parameters:

- `claimPendingLink`: atomically claim the exact confirmed transaction/SDK attempt, validating explicit confirmation, authorized initiator/session, target, issuer/client/subject, eligibility/conflicts and expiry. Durably schedule crash reconciliation before returning.
- `commitPendingLink`: after the SDK's fresh fixed access check, recheck eligibility/conflicts and atomically create the exact `(issuer, subject)` mapping for that target with the local completed linking state. Return account/session only after confirmed local commit. The SDK requires the account to equal the original target and the session to equal the current trusted browser binding; any regeneration must be explicitly verified by this adapter.
- `verifyPendingLinkCommit`: independently prove this exact attempt's committed mapping/state/association through a read-only lookup. It must not create or retry a mapping. This supports a lost local-commit acknowledgement.
- `abortPendingLink`: idempotently consume this attempt and roll back/compensate only its mapping. Never affect a newer/foreign attempt. Durable host reconciliation must finish this if compensation is unavailable or the worker dies.

Its inherited `validate` must also support `complete_pending_link`, permit only its exact claimed transaction during the internal `check_application_access`, and enforce ordinary owner/session policy after promotion. Original initiator/link data remains protected audit provenance; it is not authority to use the target's completed context as the administrator.

The SDK first records an encrypted version-fenced completion marker. Only that private invocation may perform the fresh check/necessary refresh; other checks/restores/completers are blocked. It then enters nonusable `link_binding`, verifies the confirmed host commit, and atomically changes purpose to ordinary with immutable target ownership and a new release-proof hash. The proof preimage is returned only after acknowledged activation or exact read-only reconciliation of the same committed activation. Old pending references remain unusable. Promotion extends neither the original short pending deadline nor the absolute lifetime; later authorization is needed when either expires. Completed use follows normal owner/session policy and cannot transfer accounts.

Failure consumes/destroys only the owned attempt; host rollback/compensation and context erasure are required. Store unavailability leaves the durable marker/stage blocked, or an active row with no released proof, until recovery/cleanup. Neither an old reference nor a stored hash can reconstruct completion. A replay never creates another mapping or rechecks access. The SDK never repeats the host commit after an uncertain acknowledgement: it performs only exact read-only verification, otherwise aborts and requires fresh authorization.

The host must safely persist the returned `serverReference()` in its protected association before declaring its workflow complete. If the process dies or the result/receipt is lost before that persistence, the host's durable job must consume/compensate the exact attempt and destroy its SDK context, then require fresh authorization. An authorized recovery worker can read metadata, match `completion.attempt` against its durable claim, and conditionally call the store's `delete(id, version, 'unusable')`; reload/recheck on a version conflict and never target another attempt. These are protected operational services, not HTTP endpoints. Repeat abort/deletion safely, preserve the recovery job across outages, and let bounded cleanup expire abandoned stages. Never reopen a linking transaction or reconstruct a proof from the database. Ordinary logout deletes credentials without deleting an already successfully completed identity mapping.

No SDK transaction spans IAM and the host database. The access answer is point-in-time and can race IAM eligibility changes before local commit. The host owns confirmation, uniqueness/conflict constraints, transactional mapping and durable compensation; none of those HRM behaviors is provided by this SDK package. Full production readiness depends on implementing and testing that adapter/recovery job.

Before logout/session invalidation, unlink, cancellation, account security events or context replacement, invoke `$doxa->deleteCredentialContext($context)` while the trusted host binding can still be validated. Deletion is local, idempotent and version-fenced; it erases access/refresh material but does not revoke IAM tokens or end IAM sessions. A wrong caller cannot delete another owner's context. Hosts must arrange durable cleanup/reconciliation if storage is unavailable and keep associations protected; losing a record requires fresh authorization.

Run cleanup explicitly from a protected operational scheduler:

```shell
php artisan doxa:credentials:cleanup --limit=100
```

The bounded command expires staged/unbound/binding/pending/active records whose deadlines are due, erases secret material, and later purges tombstones. It never performs network calls. A deleted/expired/unusable record cannot be restored by stale binding or cleanup. Backups and restores must not resurrect obsolete credential versions. Preflight verifies the primary context-ID key, unique authorization provenance, standalone status/expiry/purge indexes, required columns, nonnullable required fields and integer lifecycle/version fields. Missing constraints reject managed login before discovery/redirect; nothing repairs the schema automatically. Catalog inspection is implemented and tested only for SQLite and PostgreSQL; other drivers, including MySQL/MariaDB, reject managed readiness until supported and verified. The database store uses atomic conditional updates on identifier/version/status/expiry and a separate instrumented connection; no process-local mutex or file-cache lock is treated as distributed credential coordination. Credentials remain outside the ten-minute authorization transaction cache. Storage mutations reject ambient transactions so a later host rollback cannot undo an SDK operation already reported as durable. Ordinary association must verify a host mapping committed before binding; pending-link completion uses the staged host protocol above.

Access expiry is conservatively based on token-request start, with a default 60-second safety margin configurable within 0-60 seconds. No refresh token is invented when absent; unknown refresh expiry is recorded as unknown. Receiving/retaining a refresh token does not itself renew local sessions or prove current IAM access; only the fixed operation above drives lazy refresh. See `verification.md` for database/matrix evidence and remaining driver limits.

For development verification, `python tools/verify-managed.py --composer-phar /path/to/composer.phar --postgresql` runs the sequential framework matrix with the existing compatibility profiles. Configure `PGHOST`, `PGPORT`, `PGUSER`, optional `PGPASSWORD`, and `DOXA_CREDENTIAL_TEST_DATABASE` first; the database name must begin with `doxa_sdk_`. Every fixture creates/drops only its random SDK test table. Use an isolated database, never an application database. The runner retains audit reports separately; historical compatibility resolution needs the explicit `--historical-advisories` option where Composer blocks the pinned fixtures. `--reuse-locks` rechecks source using matching previously generated locks/audits without new resolution. It still runs full suites, database races, static analysis, style, strict validation and platform checks, and restores baseline vendor afterward. This tooling needs Python only for development, not SDK runtime.

### Durable refresh coordination — B1 internal groundwork

B1 adds the internal `RefreshCredentialStore` capability and protected `RefreshAttempt` references. It does **not** send refresh requests, acquire tokens for resource calls, expose a client refresh API, check application access or promote pending links. Identity-only APIs and configuration remain unchanged. Custom Milestone A stores remain valid; B1 primitives fail closed unless the store explicitly implements the new capability. Trusted host adapters must validate the internal `refresh` operation with the same live owner/session/identity checks as restoration. There is no token getter.

The standalone B1 primitives accept only completed ordinary contexts; completion proof is required throughout ordinary refresh. The private fixed access-check path additionally supports purpose-bound pending-link refresh. Claims use a random 256-bit attempt identifier, monotonically increasing row versions and a bounded 1–60 second deadline (default 30). The existing encrypted payload holds the phase and original context expiry, and the existing indexed `expires_at` temporarily holds the attempt deadline. No migration or index change is required. The absolute context lifetime is never extended.

`claimRefresh` records `claimed`; `dispatchRefresh` durably changes it to `dispatched` and returns a new attempt reference only after acknowledgement. This names **dispatch intent**, not a network operation. Future exchange code must not send before that acknowledgement, retry a failed intent transition, or reuse an attempt to send again. A timeout never proves non-dispatch. `releaseRefresh` accepts only a still-live `claimed` attempt; cleanup may release an expired pre-dispatch claim using a new version because the durable phase and conditional update fence every possible dispatcher. No access decision is produced by release.

After dispatch intent, release/reclaim is prohibited. Explicit `failRefresh` or overdue-attempt cleanup erases credentials and records nonusable uncertainty, requiring fresh authorization. Store outages keep the durable fence effective. Absolute expiry of a never-dispatched claim is ordinary terminal expiry. Cleanup is still explicitly scheduled by the host; no timer or job is installed automatically.

`completeRefresh` accepts only internal, already validated replacement material for the exact live attempt/version. It atomically replaces both credentials, expiry and granted scopes, requires a distinct nonempty refresh token and prevents scope expansion. The integrated operation validates the HTTP response/protocol using B2 before this boundary. Lost completion acknowledgement can be reconciled locally only against the exact committed attempt, version and identical replacement fields; this never resends a token. Older attempts cannot alter later success. The integrated fixed access-check path now permits pending-link refresh through private purpose-restricted helpers; the standalone B1 primitives still reject pending-link refresh. PostgreSQL and persistent SQLite remain the supported store targets; MySQL/MariaDB remains rejected.

## Identity and UserInfo

Use `issuer()` and `subject()` together as the permanent identity key. Doxa currently issues the central public UUID as `sub`; the SDK preserves it as an opaque string. Never map permanently by email, including verified email. The host owns mapping uniqueness, disabled-account policy, linking/provisioning and local authorization.

Optional accessors: `email()`, `emailVerified()`, `name()`, `givenName()`, `familyName()`, `picture()`. Unavailable fields are null; `emailVerified() === false` is preserved. Explicit JSON serialization contains only these fields plus issuer/subject. Debug output is redacted. No raw claims, internal IDs, roles, memberships, tokens or credentials are exposed.

Identity construction requires successful token validation. RS256 signature, trusted RSA JWKS (minimum 2048 bits), exact issuer, audience/authorized party, required expiry/issued-at, optional not-before, nonce and nonempty subject are mandatory. **Decoded JWT is not validated identity.** This version uses **zero clock skew**, within the contract's maximum 60 seconds; synchronize clocks. No skip-validation switch exists.

With `userinfo_enabled=true`, access-token provenance must include `openid`, the token is sent only as a bearer header, and UserInfo subject must match validated ID-token subject. Supplied allowlisted profile/email values replace earlier attributes; missing values preserve ID-token values. Issuer/subject never change. UserInfo failure fails the callback safely, rather than silently accepting enrichment or reopening the transaction. Default login skips UserInfo entirely. Identity-only login returns/persists no access or refresh token. Explicit managed login persists only the necessary encrypted credential material, separately from identity.

## Errors and host security

Catch typed exceptions under `Doxa\Laravel\Exceptions`; their `category` is safe protocol metadata. Categories distinguish configuration/discovery/capability, state/transaction expiry/replay, provider denial, exchange, signature/issuer/audience/nonce/time validation and UserInfo failures. Provider bodies and low-level exceptions are not chained into SDK exceptions. Payload validation helpers return before typed exceptions are constructed, avoiding unnecessary claim/payload arguments in traces. Legitimate sensitive public parameters use PHP sensitive-parameter redaction; its wrappers retain normal PHP behavior. `access_denied` does not prove whether a person clicked deny or IAM policy denied access.

The isolated Guzzle client emits no Laravel HTTP logging events and installs no logging or retry middleware. Config/token/identity diagnostic dumps are redacted. The host must also exclude callback query strings, Authorization headers and credential-bearing configuration from web-server/proxy logs, tracing, error pages and analytics. Protect `.env` and configuration caches; do not publish `config:show doxa` output or dump application configuration. Set production `APP_DEBUG=false`. The SDK cannot control instrumentation outside its transport/request boundary.

## Intentionally outside v1

No host local-user models, account activation/mapping implementation, employees, company membership, roles, permissions, business authorization, automatic local login, IAM administration, federated logout, revocation, invitations, MFA, signing-key rotation or other-language SDKs. The pending-link protocol delegates confirmation/mapping to an explicitly implemented trusted host adapter. Existing host sessions are not terminated automatically by IAM lifecycle changes. Those host policies/features require separate milestones.

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

### Offline backup restoration and rollback

The SDK has **no automatic backup rollback detection**. An older complete encrypted row and an old completed reference can both remain authentic. Encryption, a version counter in the same backup, or waiting for cleanup is not a recovery procedure. Apply the following after any database/replica/PITR restore, snapshot rollback, or import that might reintroduce credential rows or host credential associations. Restore normal business mappings under the host's separate policy; never resume backed-up OAuth/linking attempts.

1. Keep a durable external maintenance/readiness gate closed on **every** application node before starting restore. Stop/drain HTTP traffic, queue workers, schedulers and long-lived processes, including any stale workers connected to the old database. Disable managed credentials in effective host configuration as an additional gate; reload cached configuration/processes. The gate must survive an interrupted restore and must not itself be rolled back with the application database. Do not reopen any traffic that can restore or use a context merely because ordinary health checks pass.
2. Restore the approved business database while offline. Using the database operator console, or a protected Laravel maintenance console with the reviewed connection/table configuration, **delete every row** from the dedicated SDK credential table, including staged, pending, active, terminal records and tombstones. Preserve its schema and constraints. For example, in a protected Laravel console (never an HTTP route):

   ```php
   $connection = DB::connection(config('doxa.credentials.connection'));
   $table = config('doxa.credentials.table', 'doxa_credential_contexts');
   $connection->table($table)->delete(); // Commit; use no surrounding uncommitted host transaction.
   if ($connection->table($table)->count() !== 0) {
       throw new RuntimeException('Credential restore invalidation incomplete');
   }
   ```

   This offline operation deliberately erases all credentials without decrypting them and is repeatable after interruption. Select the actual dedicated SDK table explicitly; do not operate on an IAM token table. If separate credential databases/partitions serve the application, invalidate every one. Verify on the writer and every failover/replica that could serve the application; replicas must not replay an older snapshot after the gate opens.
3. Invalidate host server-side credential references, local sessions and pending-link/login transactions under host policy. Remove the SDK authorization-transaction namespace (`doxa.transaction.*`, including processing/exchange/terminal markers) from its configured cache using supported backend operations. A full cache flush is appropriate **only if the store is dedicated and approved**; otherwise use an operator-reviewed namespaced purge. No stale worker may remain to recreate records. If targeted cache/host invalidation cannot be verified, keep the gate closed. Never reconstruct pending authorization records or retry an old code.
4. From a fresh, isolated maintenance process under the closed traffic gate, load the approved managed configuration/key/host adapter for verification only. Verify zero credential-table rows, successful required schema readiness, purged host associations/attempts and cache namespace. Confirm a previously valid protected reference fails `restoreCredentialContext` because the record is missing (not merely because configuration or the key is disabled), and that stale conditional operations cannot recreate a missing row. Do not print the reference, credentials or ciphertext. An independent SDK test exercises this sequence on SQLite and PostgreSQL; the real deployment must rehearse it on its own restored backup/engine/topology.
5. Only after recorded operator verification, re-enable the approved managed configuration, restart fresh processes, check readiness against the invalidated database and reopen traffic. Require fresh authorization and host association for every managed context. Never copy credential rows, old context references/completion proofs, authorization-cache records, session snapshots or pending-link state back from the stale backup after invalidation. Retain old backups only under the host's access/retention policy; repeat this procedure if any are restored again.

If interrupted at any point, leave the external gate closed, stop any restarted workers and repeat invalidation/verification from step 2. The table must remain empty until fresh authorized traffic is admitted. This procedure supplies no provider-side revocation and does not claim that deleting local rows invalidates IAM-issued tokens outside this SDK. An inability to enforce the gate, erase all copies or verify recovery is a **production deployment blocker**.
