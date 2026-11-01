# Client-facing current application-access verification

An OAuth/OIDC application can verify current access without an IAM administrator. IAM remains the authority; this endpoint is not an assignment-list or user-lookup API.

## API

`GET /oauth/application-access`

Authenticate with `Authorization: Bearer <user-delegated OAuth access credential>` over HTTPS from the consuming server. The credential must be issued by this IAM, valid, unexpired, unrevoked, and include `openid`. There is no request body, query string or identity/application/client selector. Never send an ID token, query token, browser session, client secret alone or client-credentials token. The endpoint does not set a browser session or cookie.

| Status | Exact body | Meaning |
| --- | --- | --- |
| 200 | `{"effective":true}` | The authenticated identity currently has effective access to the authenticated client's application. |
| 401 | `{"error":"invalid_token"}` | Missing/invalid/expired/revoked credential, unavailable identity/client, or inconsistent issued credential context. |
| 403 | `{"error":"access_denied"}` | Current IAM eligibility denies access. No reason is disclosed. |
| 403 | `{"error":"insufficient_scope"}` | Credential lacks `openid`; the Bearer challenge identifies that scope. |
| 400 | `{"error":"invalid_request"}` | An otherwise eligible request supplies query parameters or a body. |

All these responses are JSON and `Cache-Control: no-store` (Laravel also adds `private`). Missing/invalid credentials carry a Bearer `invalid_token` challenge. Unexpected server/network failures must fail closed; they never imply access. Authentication, credential binding and current eligibility run before request-shape checking, so ineligible callers receive generic 401/403 even when supplying selectors.

## Authentication and binding

The endpoint uses the existing `RequireOidcBearerToken` and Passport/League resource validator for cryptographic signature, time and token revocation checks. It never falls back to Sanctum, web sessions or Passport browser cookies. Passport derives the identity and OAuth client from validated access-token claims, not request fields. These access credentials use the IAM's configured OAuth verification key and `aud` identifies the OAuth client; they are not ID tokens and do not adopt the ID-token `iss`/audience format. Existing OIDC issuer/ID-token validation is unchanged.

`RequireApplicationAccessContext` additionally compares the authenticated token ID, user, client and exact granted scopes with the persisted issued-token record and checks its expiry/revocation. This rejects a context whose authenticated audience/subject differs from its issued record, even if its JWT signature is valid. The application comes exclusively from that OAuth client's registered application relationship.

The existing `EnsureOAuthApplicationAccess` resource middleware then calls `ApplicationAccessService::isAllowed()`, which delegates to the canonical user application-access predicate. That predicate rereads current user/application/assignment state: active nondeleted user, active nondeleted application and active assignment are all required. Missing/deleted users fail authentication; other eligibility changes produce generic authorization denial. An inactive/detached assignment cannot be rescued by access to another application, another user's assignment, or the user's central-admin role.

## Administration and enumeration boundary

Existing `GET /api/admin/users/{user}/applications` and `GET /api/admin/applications/{application}/users` remain Sanctum-authenticated central-admin operations with `manageAccess` policy authorization. They deliberately expose administrative assignment lists and may choose users/applications. The new endpoint uses neither that policy nor admin permissions. It exposes only the one credential-bound decision, never user/application listings, numeric assignment IDs, profiles, companies, business roles, IAM roles, denial reasons or audit internals. It is read-only and creates no audit mutation or credential log. Existing lifecycle/audit events remain unchanged.

The decision is current at the request, not a cross-system transaction lock or perpetual permission. Consumers must check at the protected operation and fail closed; a later IAM state change can race any separate local operation. Deploy behind HTTPS with appropriate resource rate limits and log redaction; never configure access logs to capture Authorization headers. This milestone does not add refresh behavior or change the existing UserInfo/resource protection.

## SDK lifecycle and future adapters

The universal contract v1.2 defines this as an optional, language-neutral authenticated capability. The current Laravel SDK still implements identity-only login (v1.1): `CodeExchange` creates a private `TokenResponse`, `handleCallback` validates the ID token and optionally calls UserInfo with its access credential, and returns only `DoxaIdentity`. Refresh credentials are discarded. No credential reference survives callback; no public method can perform a later authenticated call using a `DoxaIdentity` alone.

A future `checkApplicationAccess(protectedCredentialContext)` should return a typed boolean decision or a safe error. It requires a separate explicit opt-in callback result containing identity and an opaque protected handle, plus encrypted server-side credential storage bound to host session/login, validated issuer/subject, client, granted scope and access expiry. Retrieval must enforce those bindings; expiry/logout/replacement/terminal credential failure must clean it up. The trusted provider URL must come from protected configuration or validated provider metadata; never browser input and never credential-forwarding redirects. Raw tokens must never appear in identity, public return values, serialization, debug output, logs, ordinary cookies or browser storage. No raw-token getter is an acceptable substitute.

That optional credential lifecycle is specified, not implemented in this milestone. An internal authenticated call is possible while callback's validated token response is live, but supporting a later linking confirmation needs the protected lifecycle above. Do not treat cached UserInfo or an earlier callback as proof of current access. IAM's new endpoint can be exercised directly by a controlled server-side OAuth client; SDK-based HRM live integration remains blocked until the explicit credential-context adapter is implemented. HRM/linking and refresh support are unchanged.

## Verification — 2026-10-08

Verified on PHP 8.2.12. No HRM, SDK runtime/test, refresh, dependency manifest/lock or existing IAM access-predicate changes were made. No existing security test was weakened. Nothing was staged, committed or pushed.

| Check | Result |
| --- | --- |
| New IAM access tests | 26 tests / 199 assertions passed. |
| Complete IAM SQLite suite | 423 tests / 2,919 assertions; no failures/errors; five PostgreSQL-only tests skipped. |
| Complete isolated IAM PostgreSQL suite | 423 tests / 3,015 assertions passed, including concurrency tests; no skips. |
| Complete SDK Laravel 10 suite | 219 tests / 1,892 assertions passed. |
| Complete SDK Laravel 11 suite | 219 tests / 1,892 assertions passed. |
| Complete SDK Laravel 12 suite | 219 tests / 1,892 assertions passed. |
| Restored SDK baseline suite | 219 tests / 1,892 assertions passed. |
| SDK PHPStan and Pint | Passed for every matrix profile and restored baseline. |
| IAM Pint | Complete repository check passed. |
| Composer validation/platform | Matrix fixture validation and platform checks passed; strict package and IAM manifest validation passed. |
| Whitespace | `git diff --check` passed. |

The matrix installed existing independently locked profiles sequentially, ran every complete suite (no cached result skipping), and restored baseline vendor in a finally block. Fixture manifest validation uses `--no-check-all --no-check-publish` for intentional exact compatibility pins; the real package and IAM use unqualified `composer validate --strict`. No dependency resolution update, advisory-policy override or refresh implementation was performed. Ignored new matrix evidence is in `packages/doxa-laravel/.compatibility/current-application-access/`; previous onboarding artifacts and compatibility evidence were preserved.

New endpoint tests use actual OAuth authorization-code/S256 issuance and signed access credentials, not mocked authentication. Coverage includes normal non-admin clients, missing/malformed/expired/revoked tokens, revoked clients, issued-record user/client/scope/expiry mismatch, ID-token rejection, modified audience/subject signatures, validly signed conflicting audience/subject, browser sessions/cookies/query tokens, all user/application/assignment lifecycle denials, selector rejection, own-client/identity isolation, admin non-bypass, scope enforcement, exact minimal output and no-store responses. Full unchanged SDK suites include state, nonce, PKCE, binding, replay, independent-process concurrency, ID-token/JWKS, UserInfo and safe public-error coverage. There are no new SDK high-level access-method tests because that optional API is not implemented.

The first complete IAM run encountered a Windows OpenSSL key-export fixture error in the new signed-context test. The fixture was corrected, the focused test passed, and both complete final IAM runs above passed. No validation or assertion was removed.

Changed deliverables: `backend/routes/web.php`, new OIDC `ApplicationAccessController`, new `RequireApplicationAccessContext`, new `OAuthApplicationAccessTest`, universal contract v1.2, SDK README clarification, and this document. Live HRM/provider integration and production deployment were not exercised. Access remains a point-in-time check; the optional protected SDK credential lifecycle and refresh support remain separate future work.
