# Doxa universal SDK contract

Contract version: **1.3**. Defines optional managed server-side credentials, lazy refresh with mandatory rotation, distributed concurrency safety, and high-level application-access verification. Managed-credentials revision: **2026-10-14**. Access-verification revision: **2026-10-08**. Authorization-options revision: **2026-10-07**. The original IAM inventory below retains its **2026-10-04** inspection baseline. This is a contract/design milestone only; no SDK, IAM or HRM behavior is implemented by this document.

## Optional current application-access verification (v1.2)

This is a user-delegated, authenticated capability, not an administration or identity lookup API. The provider evaluates only the identity represented by the authenticated access credential and the application registered to the credential's authenticated client. No user, subject, email, issuer, client or application selector is accepted. The provider remains the authority for current effective access; SDKs and consuming applications MUST NOT reproduce that decision from claims or local assignments.

The minimal successful response is `{ "effective": true }`. Invalid, missing, expired or revoked credentials fail authentication. Current ineligibility fails authorization with a generic denial; implementations MAY use a validated `{ "effective": false }` response instead if the provider explicitly supports it. Neither form discloses assignment identifiers, denial reasons, roles, profiles, other identities/applications or audit internals. Transport, provider, malformed-response and missing-context failures MUST fail closed and MUST NOT be interpreted as effective access. Responses MUST NOT be cached as continuing authorization. A successful check is a point-in-time decision, not an atomic guarantee for a later local operation.

A conforming optional SDK API such as `checkApplicationAccess(protectedCredentialContext)` MUST use an opaque, server-side context obtained only after successful code exchange and complete identity validation. The context MUST bind the validated issuer, subject, configured client, granted scopes and access-credential expiry to that login. It MUST NOT accept browser tokens or identity/application selectors. The authenticated call MUST use a trusted provider endpoint configured server-side or advertised through explicitly validated provider metadata, never a browser-supplied URL; credentials MUST NOT be forwarded outside that bound provider endpoint origin or through a redirect. SDKs MUST return only a typed access decision, with safe categorized errors, without returning provider bodies or credentials.

If checks span requests, a separate, explicit opt-in callback result and credential-store lifecycle are required: preserve existing identity-only callbacks; return identity plus an opaque handle only after validation and durable protected storage; bind retrieval to the initiating host session/login, issuer, subject and client; expire/delete on context lifecycle expiry, logout, replacement or terminal invalid-credential failure. Store credentials encrypted server-side, prohibit serialization/debug/logging of credential values, and never put them in `DoxaIdentity`, browser storage, browser cookies or JSON. A server credential handle MUST NOT become a transferable browser bearer capability. The v1.2 capability did not introduce refresh handling. V1.3 explicitly supersedes that limitation only for the opt-in managed flow in sections 19–26: access-token expiry may trigger safe lazy refresh rather than context deletion. Refresh MUST NOT turn an access credential into a validated identity; identity-only login still discards credentials.

The current Laravel SDK implements v1.1 login, including typed authorization options, not the optional v1.2/v1.3 managed capabilities. Its callback keeps the token response only in local scope for ID-token validation and optional UserInfo, then returns `DoxaIdentity`; no credential handle survives. It supports Laravel 10, 11 and 12. Adding a no-argument access method today would lack authenticated context after callback. Implementing the optional capability requires the explicit result/store lifecycle below and security tests before claiming SDK support. This contract milestone does not implement retention or raw-token getters.

Optional capability acceptance: test credential-only identity/client binding, current-state denial, no selector/enumeration surface, strict minimal response validation, safe errors, origin/redirect protection, expiry and server-side handle binding/cleanup, and absence of credentials from identity, return values, logs, serialization and debug output. All existing login, transaction, replay, concurrency and token-validation requirements remain mandatory.

## 1. Purpose

Every Doxa SDK establishes a cryptographically trusted central identity for a host application through OpenID Connect (OIDC). This document defines a language-neutral protocol and security contract for independently implemented SDKs. **MUST**, **MUST NOT**, and **SHOULD** express required, prohibited, and recommended behavior.

The SDK owns configuration, discovery, authorization requests, state, nonce, PKCE, authorization transaction storage, callbacks, code exchange, ID-token validation, JWKS retrieval/caching, subject extraction, optional UserInfo enrichment, normalized identity extraction, and safe protocol errors. It does not administer IAM or own application users, roles, permissions, employees, company membership, business records, or business authorization. Local sessions belong to the host; a framework adapter may establish one only under explicit host policy.

Normative requirements below apply to every SDK implementation. The following inventory describes IAM code at the original inspected baseline; SDK conformance evidence is recorded separately in each SDK's verification documentation.

## 2. Architecture

```text
Host application -> Doxa SDK -> discovered Doxa IAM OIDC endpoints
                         |
                  validated DoxaIdentity
                         |
Host application -> lookup(issuer, subject) -> local policy -> local session
```

IAM authenticates the central account and decides current access to the registered application. The SDK proves the identity returned by that exchange. The host decides whether that identity may authenticate a local account and what it may do locally. An IAM assignment grants entry through IAM; it grants no HRM role or other application permission.

### 2.1 Current implementation, verified before defining the contract

Paths below are relative to the repository root. The source of truth includes the installed Passport/League code for delegated protocol behavior, not just existing descriptions.

| Surface | Verified current behavior | Source |
| --- | --- | --- |
| `GET /.well-known/openid-configuration` | Public, session-free JSON; 300-second public cache. Advertises code/query, authorization-code and refresh grants, public subjects, RS256, S256, four scopes, three client authentication methods, UserInfo, and claims. Explicitly disables `request_uri` support. Invalid issuer/route configuration produces 503 `server_error`, no-store. | `backend/routes/web.php`; `backend/app/Services/OidcDiscovery.php`; `backend/app/Http/Controllers/Oidc/DiscoveryController.php` |
| `/oauth/authorize` | Passport GET starts authorization; POST approves; DELETE denies. Uses IAM web authentication/session, login continuation, consent, and session locking. GET requires valid unrevoked UUID client, authorization-code grant, exact registered redirect URI, `response_type=code`, nonempty S256 challenge, valid scopes, active application, and current user eligibility when authenticated. Guests continue through central login. Prior granted scopes can allow automatic approval. | `backend/config/passport.php`; `backend/bootstrap/app.php`; `backend/app/Http/Middleware/ValidateOAuthAuthorization.php`; `backend/routes/web.php`; `backend/vendor/laravel/passport/routes/web.php`; `backend/vendor/laravel/passport/src/Http/Controllers/AuthorizationController.php` |
| `POST /oauth/token` | Only `authorization_code` and `refresh_token` admitted. Code exchange binds client, exact redirect URI and verifier to the encrypted code; checks expiry and revoked status; issues bearer access/refresh tokens and, for `openid` code exchange, an ID token. Exchange and rendering share a database transaction with credential-row locks. Access tokens expire after 15 minutes. | `backend/app/Http/Middleware/ValidateOAuthAuthorization.php`; `backend/app/Http/Middleware/AtomicOAuthTokenExchange.php`; `backend/app/Providers/OAuthServiceProvider.php`; `backend/vendor/league/oauth2-server/src/Grant/AuthCodeGrant.php` |
| `GET /oauth/jwks` | Public, session-free; one public RSA signing key derived from the actual private signing key, at least 2048 bits. `kty=RSA`, `use=sig`, `alg=RS256`, thumbprint-derived `kid`, public `n`/`e` only. 300-second public cache; unavailable/invalid signing key returns generic 503 `server_error`, no-store. A mismatched loaded signing key fails closed at issuance. | `backend/app/Services/OidcSigningKey.php`; `backend/app/Http/Controllers/Oidc/JwksController.php` |
| `GET` or `POST /oauth/userinfo` | Bearer header only, no IAM cookie or query-token fallback; checks access token and current application eligibility; requires granted `openid`. JSON claims from the same claim service as ID tokens, with no-store. An ID token cannot authenticate UserInfo. | `backend/routes/web.php`; `backend/app/Http/Middleware/RequireOidcBearerToken.php`; `backend/app/Http/Middleware/EnsureOAuthApplicationAccess.php`; `backend/app/Http/Controllers/Oidc/UserInfoController.php` |
| Application/client registration | Central administrators use authenticated `/api/admin/applications` and `/api/admin/applications/{application:public_id}/oauth-clients`. Application create takes name, slug, optional description and defaults active. Client create requires active application, name and one or more URL redirect URIs without fragments; `confidential` defaults false. Clients receive UUID IDs and authorization-code/refresh grants. Confidential secrets are generated, stored hashed by Passport, and returned in plaintext only at creation. Read resources omit secrets. Client revoke revokes associated access/refresh tokens through Passport. No OIDC dynamic-registration endpoint is advertised. | `backend/routes/api.php`; `backend/app/Policies/ApplicationPolicy.php`; `backend/app/Services/ApplicationService.php`; `backend/app/Services/OAuthClientService.php`; `backend/app/Http/Requests/Applications/StoreOAuthClientRequest.php`; `backend/app/Http/Controllers/Applications/OAuthClientController.php`; `backend/app/Http/Resources/OAuthClientResource.php`; `backend/vendor/laravel/passport/src/Client.php`; `backend/vendor/laravel/passport/src/ClientRepository.php` |
| PKCE | S256 required for both public and confidential authorization requests. League checks challenge/verifier format (43–128 allowed characters) and validates the verifier against the code-bound challenge. Missing/malformed verifier fails; mismatch fails. SDKs must generate the canonical S256 challenge, not rely on the provider's broader accepted challenge syntax. | `backend/app/Http/Middleware/ValidateOAuthAuthorization.php`; `backend/vendor/league/oauth2-server/src/Grant/AuthCodeGrant.php` |
| Scopes | `openid`, `profile`, `email`, `iam:read`; no default scopes. `profile`/`email` authorization requires `openid`. `iam:read` alone is plain OAuth and gives no ID token; it is not an application role or permission. `offline_access` is unsupported. | `backend/app/Providers/OAuthServiceProvider.php`; `backend/app/Http/Middleware/ValidateOAuthAuthorization.php` |
| ID-token claims | RS256 with `kid`; `iss`, `sub`, `aud` (issued client), `iat`, `exp` (access-token expiry), bound `nonce`. Granted `email` adds `email` and boolean `email_verified`; granted `profile` adds available `name`, `given_name`, `family_name`, `picture`. Empty profile values are omitted; picture must be an HTTP(S) URL, never a local storage path. No IAM roles/admin status, assignments, company memberships, internal numeric IDs or sessions are included. | `backend/app/Services/OidcIdToken.php`; `backend/app/Services/OidcUserClaims.php`; `backend/app/Http/Responses/OidcBearerTokenResponse.php` |
| UserInfo claims | Always public `sub`, plus the same scope-gated profile/email fields, using current user data and token-granted scopes. No `iss`/`aud`/`nonce` protocol envelope is returned. Request parameters cannot select another user or elevate scopes. | `backend/app/Services/OidcUserClaims.php`; `backend/app/Http/Controllers/Oidc/UserInfoController.php` |
| Issuer | Discovery and ID tokens resolve `config('oidc.issuer')` (`OIDC_ISSUER`), falling back only when null to `config('app.url')` (`APP_URL`). Exact string preserved, including trailing slash/base path. Discovery builds endpoints from issuer plus actual route paths, independently of incoming Host headers. Discovery accepts HTTP or HTTPS, rejects credentials/query/fragment and invalid URLs. Deployment must actually route any configured base path. | `backend/config/oidc.php`; `backend/app/Services/OidcDiscovery.php`; `backend/app/Services/OidcIdToken.php` |
| Subject | Public subject is `User.public_id`, a generated public UUID shared across clients, not email or internal numeric user ID. Normal account/profile edits do not accept public-ID changes. `User` has no explicit model-level public-ID immutability guard. | `backend/app/Models/User.php`; `backend/app/Services/OidcIdToken.php`; `backend/app/Services/OidcUserClaims.php`; `backend/tests/Feature/OidcIdTokenTest.php` |
| Nonce | `openid` authorization requires a nonempty string of at most 255 bytes. Server-side consent transactions bind nonce to client and redirect URI, separated per consent token, expiring after 600 seconds. Code row stores nonce atomically with issuance. ID token uses only nonce from successfully redeemed code; token-request nonce cannot substitute. Plain OAuth/refresh never gains an ID token. | `backend/app/Http/Middleware/OidcAuthorizationTransaction.php`; `backend/app/Repositories/OidcAuthCodeRepository.php`; `backend/app/Services/ExchangedOidcNonce.php`; `backend/app/Http/Responses/OidcBearerTokenResponse.php` |
| Authorization-code lifecycle | Passport configures a 10-minute code TTL. Encrypted code carries client, redirect URI, scopes, user, expiry, code ID and PKCE binding; database row carries revoked status and OIDC nonce. Successful redemption revokes the code. Row locks serialize competing redemptions. Failed validation/issuance/rendering rolls back, so an unexpired code can remain usable; successful code replay fails. Redirect URI is validated from encrypted code, not a separate database column. | `backend/vendor/laravel/passport/src/PassportServiceProvider.php`; `backend/vendor/league/oauth2-server/src/Grant/AuthCodeGrant.php`; `backend/app/Repositories/OidcAuthCodeRepository.php`; `backend/app/Http/Middleware/AtomicOAuthTokenExchange.php` |
| Refresh | Issued with code exchange without `offline_access`; 7-day lifetime. Successful refresh rotates refresh token and revokes the previous refresh/access tokens; scopes may stay the same or narrow, never expand. Client binding, expiry, revoked state and current eligibility are checked. Row locks prevent double redemption. Ineligible refresh is permanently revoked even on rejection. Refresh returns no ID token. No token-family-wide reuse response or downstream-session termination is established here. | `backend/app/Providers/OAuthServiceProvider.php`; `backend/app/Repositories/IamRefreshTokenRepository.php`; `backend/vendor/league/oauth2-server/src/Grant/RefreshTokenGrant.php`; `backend/app/Http/Responses/OidcBearerTokenResponse.php` |
| Application-access eligibility | Requires existing nondeleted active user, existing nondeleted active application, active `application_user` assignment and unrevoked client. Checked at authorization, code issuance, access-token issuance (including refresh), and UserInfo. Central administrator status does not bypass assignment. Company membership/state, email verification and local HRM status/roles are not inputs. Approval additionally requires the same user and current IAM browser-session version. | `backend/app/Models/User.php::applicationAccessState`; `backend/app/Services/ApplicationAccessService.php`; `backend/app/Services/OAuthAccessEligibility.php`; `backend/app/Repositories/OidcAuthCodeRepository.php`; `backend/app/Http/Middleware/EnsureAccountIsActive.php` |
| Client authentication | Public clients: `none` with client ID. Confidential clients: `client_secret_basic` or `client_secret_post`, checked against hashed secret. Body credentials take precedence over Basic credentials in League; SDK must send exactly one method. No private-key JWT, client-secret JWT or mutual TLS authentication is advertised. PKCE remains mandatory with a secret. | `backend/app/Services/OidcDiscovery.php`; `backend/app/Repositories/IamClientRepository.php`; `backend/vendor/league/oauth2-server/src/Grant/AbstractGrant.php`; `backend/vendor/laravel/passport/src/Bridge/ClientRepository.php` |

### 2.2 Mismatches and integration limits

These were identified from code before defining the universal SDK contract; none is fixed in this milestone.

1. **State enforcement belongs to the SDK.** IAM/League accepts an absent state and echoes supplied state. The SDK MUST require unpredictable, browser-bound state and reject missing/mismatched state even though IAM does not require it.
2. **Refresh is not fresh authentication.** IAM issues refresh tokens without `offline_access` and omits ID tokens on refresh. The contract cannot promise refreshed identity proofs, silent login by refresh, or `offline_access` support. Minimal SDK login need not retain or use refresh tokens.
3. **Central eligibility is not downstream session revocation.** IAM rechecks its own issuance/resource boundaries. There is no implemented propagation guarantee that disabling a central user, revoking an assignment or ending IAM login terminates an existing host session. The host needs its own session policy; immediate cross-application termination is future work.
4. **Production transport is stricter than current registration/discovery validation.** Current validation permits HTTP and does not enforce a production HTTPS deployment. SDK production requests and callbacks MUST use HTTPS; explicit loopback development exceptions do not relax token validation.
5. **Identity stability requires operational discipline.** Public UUID subjects exist and survive normal email/profile edits, but no model immutability guard prevents privileged direct mutation. Changing the exact issuer or public UUID changes the identity key; freeze production issuer and preserve subjects. No automatic identity migration is promised.
6. **Published-key overlap is absent.** JWKS publishes one current key, not an old/new key overlap set. SDK bounded refresh on unknown `kid` helps retrieve the current key but cannot guarantee validation of old tokens after replacement. Rotation/overlap policy remains future IAM work.
7. **Failure transport is mixed.** Some authorization failures are local JSON or framework responses instead of redirects to the callback; see section 12. SDK cannot handle callbacks IAM never sends and must not claim every denial arrives in the same format. A callback `access_denied` does not reliably distinguish a user click from other authorization denial.
8. **Existing descriptions can be stale.** `docs/user-lifecycle-contract.md` still describes OIDC as future/unimplemented; actual endpoints and tests now exist. This inventory supersedes those statements for the SDK baseline without rewriting that document.

No protocol blocker was found for a server-side SDK login pilot using the inspected code. This is a source-level conclusion, not a claim of production deployment verification.

## 3. Terminology

| Term | Meaning |
| --- | --- |
| Issuer | Exact trusted OIDC provider identifier configured by the host and verified in discovery/ID token. |
| Subject | Provider's nonempty opaque `sub`; current Doxa public UUID. |
| Federated identity key | Ordered pair `(issuer, subject)`; both values must match. |
| OAuth client | Registered protocol client with its own client ID, redirect URIs and optional secret; belongs to an IAM application. |
| IAM application | Central registry/access boundary; distinct from the host's local user/role database. |
| Authorization transaction | SDK's protected server-side login attempt; distinct from IAM's consent transaction and authorization-code row. |
| ID token | Signed identity proof intended for the registered client, validated before extracting identity. |
| Access/refresh token | IAM resource credential/renewal credential; neither is the host's local session or a substitute ID token. |

## 4. Universal configuration

Every implementation MUST support the following conceptual `DoxaOidcConfig`; ecosystem naming may differ.

| Field | Requirement |
| --- | --- |
| `issuer` | Required trusted absolute issuer URL. No userinfo, query or fragment. Preserve exact value for comparisons; do not normalize away identity differences. HTTPS in production. |
| `client_id` | Required registered client ID; not IAM application's public ID. |
| `client_secret` | Required only for confidential registration; absent for public clients. Server-side protected secret, excluded from serialization/logging. |
| `redirect_uri` | Required exact registered callback URL, no fragment. Fixed trusted configuration, not derived from untrusted request Host or callback parameters. HTTPS in production. |
| `scopes` | Explicit list containing `openid`; safe default `openid`. Optional `profile`/`email` as needed. Reject unsupported scope requests. No implicit `iam:read` or `offline_access`. |
| `pkce_required` | Required invariant `true` (may be represented as a fixed documented setting). Only S256; false is a configuration error. |
| `http_timeout` | Positive bounded timeout applied to discovery, JWKS, token and UserInfo requests; document implementation default. |
| `discovery_cache` / `jwks_cache` | Bounded caching policy with finite TTL/maximum age, cache key including issuer and relevant URL, and safe defaults. Implementations may use native cache adapters. Honor provider cache directives within local bounds; do not cache error responses as successful metadata/keys. |

A confidential implementation MUST use an advertised secret method. Prefer `client_secret_basic` when supported; `client_secret_post` is an allowed alternative. An optional method selector is needed only if the implementation exposes that choice. Public clients use `none`; never infer that a failed secret exchange permits downgrade to public authentication.

The issuer is the only authoritative discovery base. Fetch its OIDC well-known configuration (append `/.well-known/openid-configuration` to the issuer path with a single joining slash). Verify discovered `issuer` equals configured issuer exactly. Obtain authorization endpoint, token endpoint, JWKS URI and optional advertised UserInfo endpoint from that document; never hard-code those endpoint URLs or accept browser-supplied endpoint overrides.

Validate advertised scopes, signing algorithms, PKCE methods, response/grant types and client authentication capabilities before login. Required baseline is code/query, authorization-code, public subject, RS256, S256 and `openid`. Missing required endpoint/capability produces a safe discovery or unsupported-capability error, never a downgrade. UserInfo and refresh capability are not prerequisites for minimal login.

Use TLS certificate verification and bounded HTTP responses. Reject unsafe endpoint schemes/credentials/fragments. Endpoint host equality to issuer is not an OIDC requirement: any different origin must come from validated discovery fetched from the trusted issuer. Do not follow token/UserInfo redirects that could forward credentials to another endpoint. Framework-specific transport/cache wiring is an adapter concern, not an extra universal business configuration.

## 5. Authorization transaction

The SDK MUST create and protect server-side `DoxaAuthorizationTransaction` data before returning an authorization URL.

| Field | Meaning |
| --- | --- |
| `state` | Unique cryptographically random correlation value. |
| `nonce` | Independently cryptographically random ID-token binding value; at most 255 bytes for current IAM. |
| `pkce_verifier` | Independently cryptographically random, RFC 7636-compatible verifier, 43–128 allowed characters. |
| `requested_at` / `expires_at` | Server timestamps with finite TTL; baseline maximum 10 minutes from creation, never extended during callback handling. |
| `redirect_uri` | Exact callback from trusted configuration. |
| `client_id` | Registered client bound to this attempt. |
| `requested_scopes` | Immutable scopes, including `openid`. |
| `status` | `pending`, `processing`, `succeeded`, `failed`, or `expired`; only pending can be claimed. |
| `issuer` / provider context | Exact issuer and validated discovery context bound to the attempt, including endpoint selection. |
| Browser binding | Protected session/correlation binding proving callback belongs to the initiating browser. |

Use a CSPRNG with at least 256 bits of entropy separately for state, nonce and verifier. A 32-byte base64url value is a suitable 43-character verifier. Compute `BASE64URL_NO_PADDING(SHA256(ASCII(verifier)))` for S256. The application MUST NOT manually construct or pass replacements for these values.

Storage may use a host-supplied server-side adapter, but the SDK owns secure generation, validation, expiry and consumption semantics. Protect verifier/nonce and immutable context against substitution; never place them in a browser-readable transaction object. A Secure, HttpOnly, appropriate SameSite correlation cookie may hold an opaque browser binding. State alone must not allow a different browser to claim another user's attempt. Multiple concurrent logins must remain isolated rather than overwriting a single slot.

Atomically claim a valid browser-bound pending transaction as processing before any exchange, and permit at most one callback to proceed. Validate state using a timing-safe comparison where applicable. Unknown/missing/mismatched state or browser binding MUST NOT exchange a code or consume another unrelated attempt. Once claimed, success, provider error, validation failure and uncertain token-exchange outcome all make it terminal: do not reopen/retry that transaction. Retain a bounded protected terminal marker until expiry to identify replay; delete secret values on completion. An expired transaction cannot authenticate. A new login is required after terminal failure.

Neither callback parameters nor host request input may replace stored issuer, client ID, redirect URI, scopes, nonce or verifier. These bindings, atomic consumption, expiry and browser correlation protect against state/nonce/verifier/redirect substitution and replay. IAM's rollback allowing a failed code exchange to be retried does not relax the SDK's one-attempt callback rule.

## 6. Login flow

| Step | Owner and required behavior |
| --- | --- |
| 1 | **Application:** render “Continue with Doxa” and invoke SDK login. |
| 2 | **SDK — protocol:** validate config/discovery; generate state, nonce and S256 verifier/challenge; persist browser-bound transaction. |
| 3 | **SDK — protocol:** build discovered authorization URL with `response_type=code`, stored client ID, exact redirect URI, scopes, state, nonce, `code_challenge` and `code_challenge_method=S256`. |
| 4 | **Application/framework adapter:** redirect browser to that URL. |
| 5 | **IAM:** authenticate central user, check current application access, collect consent if needed, issue code and return code/state via query redirect. |
| 6 | **Application/framework adapter:** deliver callback parameters and protected browser context to SDK. |
| 7 | **SDK — protocol:** reject ambiguous/duplicate security parameters; validate state, browser binding, transaction expiry/context; atomically claim transaction. Handle provider error before exchange. Require a single code on success; reject code+error. |
| 8 | **SDK — protocol:** POST code to discovered token endpoint with `grant_type=authorization_code`, stored redirect URI/client context/verifier and correct client authentication. Never automatically retry an uncertain exchange. |
| 9 | **SDK — protocol:** validate token response and required ID token; retrieve/cache JWKS; cryptographically validate ID token including exact issuer, audience, expiry, nonce and subject. |
| 10 | **SDK — protocol:** extract normalized identity; optionally enrich with matching-sub UserInfo; make transaction terminal and return result. |
| 11 | **Application:** `lookup(issuer, subject)`; enforce local account/linking/provisioning policy and local eligibility. |
| 12 | **Application:** establish its own local session according to host framework/policy. |

Authorization codes are opaque. SDK MUST NOT decode IAM's encrypted code or depend on internal database IDs. Callback handling must remove sensitive query parameters from subsequent navigation, suppress referrer leakage and avoid logging callback URLs. Browser code redirects are part of this flow; access tokens, refresh tokens and secrets MUST NOT be carried in URLs.

### 6.1 Authorization options

`beginLogin` MUST accept an optional immutable, typed/validated `AuthorizationOptions` value supplied explicitly by trusted host application code. SDKs MUST own authorization request construction; an arbitrary query-parameter map or browser/callback parameter passthrough is prohibited. The first supported field is `prompt`, with the exact allowlisted value `login` or absence. Reject every other value, including empty strings, whitespace/case variants, combined values, `none`, `consent` and `select_account`, before redirecting. Implementations MUST expose a safe unsupported-option error without echoing rejected input or chaining low-level causes. Ecosystem-specific type errors for incorrectly typed arguments MUST occur before any authorization request/transaction side effects.

| Login intent | Outgoing request | Contract behavior |
| --- | --- | --- |
| Default login | No `prompt` parameter | Preserve the existing provider/SSO behavior. Omitting options or providing empty/default options MUST preserve the existing URL construction, apart from fresh per-attempt randomness. |
| Forced reauthentication | Exactly one `prompt=login` | Request provider reauthentication explicitly. This is not account selection and does not guarantee switching accounts, authentication-time claims or proof of a particular authentication method. |
| Future account selection | `prompt=select_account` | Unsupported in this revision/current Doxa SDK capability. A future explicit contract/SDK addition requires IAM/provider support; never silently accept, substitute or claim this feature today. |

These meanings follow [OpenID Connect Core, Authorization Request](https://openid.net/specs/openid-connect-core-1_0.html#AuthRequest): `login` requests reauthentication; `select_account` requests account selection. A host workflow requiring reauthentication MUST choose the option in its own trusted server-side code rather than copying request query parameters. The SDK MUST NOT infer options from login-route browser input or callback input. Options MUST NOT replace response type, client/issuer/endpoint/redirect/scopes, state, nonce, verifier or PKCE method, and MUST NOT duplicate or shadow authorization query parameters. Preserve RFC 3986 encoding and the existing rejection of authorization endpoints with query parameters that could shadow SDK parameters.

Options influence only the outgoing authorization request. They do not change transaction format, generation, persistence, browser binding, expiry, atomic claims/provenance, replay handling, code exchange or identity/UserInfo validation. They are not token/callback validation inputs and are not accepted from callbacks. No refresh, linking, provisioning or downstream application policy is introduced.

## 7. Token validation

**“Decoded JWT” != “validated ID token.”** Parsing claims is not authentication. Before returning any `DoxaIdentity`, the SDK MUST perform all checks below with a maintained JOSE/OIDC library or equivalent verified implementation.

1. Require a well-formed signed ID token from the successful code exchange. A plain OAuth response, missing ID token or access token substituted as ID token must fail.
2. Allow only **RS256** in v1; intersect local allowlist with discovery capability. Reject `none`, symmetric substitutions, unsupported algorithms and algorithm/key confusion. Discovery alone cannot expand the local allowlist.
3. Select the `kid` from trusted issuer JWKS. Require compatible RSA signing key, at least 2048 bits, appropriate key use/operations when present, and no conflicting algorithm. Reject missing/ambiguous/unusable keys. Never fetch JWT-header `jku`/`x5u` or trust token-provided keys.
4. Cryptographically verify the signature over the token. No claims can drive identity, session or authorization before this succeeds.
5. Require `iss` exactly equal to the transaction/configured issuer and validated discovery issuer; no trailing-slash/host/case normalization to make a mismatch pass.
6. Require `aud` to contain the transaction's client ID. For multiple audiences, require `azp` equal that client ID; if `azp` is present with one audience it must also match. Current Doxa issuance has one client audience; do not require `azp` when absent in that case.
7. Require numeric `exp` and `iat`; reject expired tokens, issuance unreasonably in the future and invalid temporal ordering. Use a documented small bounded clock skew (maximum 60 seconds), never disabling time checks. Validate `nbf` if present. Do not require unsupported `auth_time`/`acr`/`amr` claims or mistake `iat` for authentication time.
8. Require nonce to equal the protected transaction nonce exactly; missing/non-string/mismatched nonce fails. Never validate against a nonce provided in the callback or token request.
9. Require a nonempty string `sub` and return it unchanged as subject. Treat it as opaque; current UUID format does not justify mapping by numeric IDs or email.

JWKS caches are isolated by issuer/JWKS URI. On unknown `kid`, perform at most one bounded forced refresh for that validation, rate-limit refreshes, and fail closed if key remains unavailable. Expired caches or network failure never permit skipped validation. Use only bounded, unexpired cached data; no indefinite stale-key fallback. Current single-key publication does not promise seamless old-key validation after rotation.

Refreshing access tokens, if an SDK later exposes it, is a separate optional server capability. Current IAM refresh does not return an ID token, so it MUST NOT produce a new validated login identity or bypass a new authorization transaction.

## 8. Normalized identity

```text
DoxaIdentity {
    issuer: required string,
    subject: required nonempty string,
    email?: string,
    email_verified?: boolean,
    name?: string,
    given_name?: string,
    family_name?: string,
    picture?: string
}
```

`issuer + subject` is the stable federated identity key. Subject MUST NOT be replaced by email. Email MUST NOT be the permanent mapping key, including when verified. The same subject at different issuers is a different identity; changed email does not create a different identity. Display/account-linking workflows may consider email under explicit host policy.

Validate optional claim types; omit absent claims rather than inventing values. `email_verified=false` is distinct from unavailable verification status. Name/photo/email are identity attributes, not business permissions or evidence of local eligibility. Current profile/email claims depend on granted scopes and available data; none is guaranteed just because requested.

An implementation MAY offer explicitly requested read-only validated claims with provenance (ID token versus matching-sub UserInfo). Safe exposure means allowlisted nonsensitive claims, no credential material, no default logging/serialization, and no authorization interpretation. Unknown/raw claims MUST NOT be spread into the normalized identity or treated as application authorization data.

IAM roles, IAM admin status, application assignments, HRM roles, company membership, internal database IDs, sessions, passwords and secrets are excluded from this contract. The public UUID carried in `subject` is the federated subject, not permission to expose other internal identifiers. Any expansion needs an explicit future contract decision.

## 9. UserInfo

UserInfo is **optional enrichment**, not the primary identity proof. Skip it when the validated ID token contains everything needed. Invoke only an advertised discovered UserInfo endpoint, with `Authorization: Bearer <access_token>` and an access token from the same issuer/client transaction whose granted scopes include `openid`. Never send an ID token, a query token or browser session as its credential.

Require a nonempty string UserInfo `sub` exactly matching validated ID-token `sub` before merging any fields. Reject mismatch; never change issuer or subject based on UserInfo. The response currently has no `iss` or `aud`, so preserve context through trusted endpoint selection and token provenance rather than inventing those fields.

Matching UserInfo may enrich available profile/email attributes, or supply current values under a documented precedence rule. It cannot override the validated federated identity or protocol claims. Malformed responses, missing/mismatched sub, HTTP failures and insufficient scope produce a safe UserInfo error. If optional enrichment fails, a host may explicitly choose to continue with the already validated ID-token identity; a mismatched response must always be discarded. No implicit roles, local account changes or fallback to unvalidated identity is allowed.

## 10. Token-storage boundary

IAM access tokens belong to the application/server integration. Confidential secrets MUST remain server-side. Refresh tokens, when retained, MUST remain server-side in protected storage with restricted access and encryption appropriate to the host environment. Credentials MUST be excluded from exceptions, debug dumps, analytics and routine serialization.

The default application-facing login result contains identity and safe metadata, not raw tokens. SDK may use access tokens internally for UserInfo and discard credentials when not needed. Any optional server-only token result/store must be explicit, separate from `DoxaIdentity`, and never automatically serialized to the browser. Do not retain refresh tokens solely because IAM returned them.

Applications MUST NOT expose IAM access/refresh tokens to browser JavaScript unless a deliberately designed separate architecture requires it; this server-side v1 contract does not define that architecture. Tokens/secrets MUST NOT appear in URLs, browser storage or ordinary cookies. Opaque protected correlation/local-session cookies are separate.

The host's local session and IAM session are separate. SDK MUST NOT automatically make an IAM access token the host's local authentication credential. ID-token expiry constrains callback validation, not an implicit host-session expiry rule. IAM refresh does not automatically prolong the host session; IAM logout or access revocation does not promise to terminate it.

## 11. Local user mapping

After a successful SDK callback:

```text
identity = SDK.handleCallback(callback, browser_context)
local_user = application.lookup(identity.issuer, identity.subject)
if local_user exists:
    application checks local eligibility and authenticates that user
else:
    application applies explicit linking/provisioning policy
application establishes a local session only when its policy permits
```

The host SHOULD enforce uniqueness of the `(issuer, subject)` mapping. A mapping does not bypass a disabled local account or other host policy. A different subject with the same email is not automatically the same local user. Linking requires host-defined proof and conflict handling.

SDK MUST NOT automatically create an employee, HRM account or business record; create company membership; assign roles/permissions; activate a local account; or link by email. Explicit host provisioning policy may do application work after validated identity is returned; that work remains outside the universal SDK.

## 12. Error model

Expose stable language-neutral categories, with idiomatic exception/result types per ecosystem. Include only safe stage/category, retry guidance and nonsensitive correlation metadata. Specific validation errors may be subcategories of `invalid_id_token`; no category permits skipping validation.

| Category | Meaning |
| --- | --- |
| `configuration_error` | Invalid issuer/client/redirect/scopes, missing confidential secret, insecure production configuration or disabled PKCE. |
| `discovery_error` | Discovery retrieval, parsing or metadata trust failure. |
| `unsupported_provider_capability` | Required flow, response mode, scope, algorithm, PKCE method or authentication method unavailable. |
| `authorization_error` | Provider callback error or malformed/ambiguous callback after correlation validation. |
| `user_denied_authorization` | Correlated `access_denied` callback; safe UI may say authorization was denied. Do not claim IAM can always distinguish deliberate user denial from policy denial. |
| `invalid_state` | Missing/mismatched/unknown state or invalid browser correlation; no exchange. |
| `invalid_nonce` | Missing/mismatched ID-token nonce. |
| `invalid_pkce_transaction` | Missing/corrupt/substituted stored verifier or challenge binding; no exchange. A provider verifier rejection may only be knowable as token exchange failure. |
| `token_exchange_failure` | HTTP/network failure, OAuth token error, malformed token response or uncertain outcome. No automatic code retry. |
| `invalid_id_token` | Missing/malformed ID token, forbidden algorithm, invalid temporal/subject/protocol claims. |
| `invalid_issuer` | ID-token or discovery issuer mismatch. |
| `invalid_audience` | Wrong client audience/authorized party. |
| `invalid_signature` | Modified token, invalid signature or unusable trusted signing key. |
| `expired_id_token` | Expiry outside allowed skew. |
| `identity_extraction_failure` | Validated identity cannot be represented safely. Missing/invalid token sub must fail validation before extraction. |
| `userinfo_failure` | Enrichment request/response failure, missing or mismatched UserInfo sub. |
| `transaction_expired` | Attempt exceeded stored expiration. |
| `transaction_replay` | Attempt was already claimed/terminal; no repeated exchange. |

Do not expose provider descriptions or raw response bodies verbatim to users/logs. Never leak authorization codes, verifier, nonce, state/correlation secrets, client secrets, tokens or sensitive claims in errors. Missing terminal markers may yield invalid state rather than a precise replay category; either must reject without exchange. Do not infer granular causes from a generic provider `invalid_grant`.

### Current IAM error behavior relevant to adapters

| Boundary | Current behavior |
| --- | --- |
| Authorization prevalidation | Local JSON 400 `invalid_client`, `invalid_request` (redirect/PKCE/nonce), `unsupported_response_type`, `invalid_scope`; 403 `unauthorized_client` for inactive/missing application, or `access_denied` for authenticated ineligible user. These may never reach application callback. |
| IAM browser account/session | Account middleware returns local 403 message for inactive account or 401 message for stale session and invalidates IAM session. Guests take login continuation. Invalid consent token returns framework 403. |
| Consent denial | Redirect to registered callback with OAuth `access_denied` and original state. Passport also supports `prompt=none` errors such as `login_required`/`consent_required`; v1 SDK does not require silent-login support. |
| Token endpoint | Unsupported grant: 400 `unsupported_grant_type`. League missing/invalid parameters: generally 400 `invalid_request`; expired/revoked code or verifier mismatch: 400 `invalid_grant`. Wrong code client/redirect is `invalid_request`. Invalid client authentication: 401 `invalid_client`; absent required secret: 400 `invalid_request`. Current eligibility rejection during token issuance uses League 401 `access_denied`. Refresh expiry/revocation/client mismatch is 400 `invalid_grant`. |
| Issuance failure | Atomic rollback prevents partial tokens or successful code consumption if ID-token rendering/metadata/key validation fails. OAuth server failures and unexpected framework failures are not all equivalent; SDK must safely handle non-JSON/non-OAuth responses without exposing bodies. |
| Discovery/JWKS | Generic 503 `server_error`, no-store for handled configuration/key failure. |
| UserInfo | 401 `invalid_token` for absent/invalid bearer credentials, including ID-token substitution; 403 `access_denied` for current access failure; 403 `insufficient_scope` without `openid`. Bearer middleware adds challenge on invalid credentials; scope error includes `WWW-Authenticate` with required scope. Do not assume every access-denied response includes a challenge. |

## 13. Security requirements

Every SDK MUST satisfy this checklist:

- Authorization Code flow only; no implicit/password/client-credentials login or flow downgrade.
- S256 PKCE for public and confidential clients; CSPRNG verifier, no manual overrides.
- Independently random state and nonce, server-side transaction and initiating-browser binding.
- Exact configured/registered redirect URI, bound to authorization and token exchange.
- Exact discovery/ID-token issuer validation and correct client audience/authorized party.
- Cryptographic signature validation, trusted JWKS, RS256 allowlist and compatible keys.
- Required expiry/issued-at checks with bounded clock skew; nonce and subject validation.
- Atomic one-time transaction claim, expiration, isolated concurrent attempts and replay rejection.
- Server-side confidential secrets and protected optional refresh storage.
- Verified TLS in production; bounded network calls, cache lifetimes and key-refresh attempts.
- No credentials/tokens in URLs or logs; redact callback codes and prevent query/referrer leakage.
- No trusting decoded JWT claims before signature and all required validation succeed.
- Stable mapping by issuer + subject; no email-based permanent mapping or automatic linking.
- No automatic role assignment, local activation, account creation or business-record creation without explicit host policy.
- No implicit application authorization or automatic access-token-to-local-session conversion.
- UserInfo optional, matching-sub only; enrichment cannot replace federated identity.
- Fail closed on unavailable required validation; no insecure “skip verification” option.

## 14. SDK API contract

Keep the application-facing API small. Names below are conceptual and need not be identical across languages. Infrastructure adapters may provide protected transaction storage, HTTP, cache, clock and randomness; no PHP/framework interface is mandated.

| Visibility / capability | Inputs | Outputs | Errors / guarantees |
| --- | --- | --- | --- |
| Public: construct/configure client | `DoxaOidcConfig`, trusted infrastructure adapters where needed | Configured client | Configuration error; no secrets serialized, no discovery from request-controlled issuer. |
| Public: `beginLogin` | Protected browser context; optional typed `AuthorizationOptions` (section 6.1) | `LoginStart { authorization_url, expires_at }` | Configuration/discovery/capability/transaction-storage or safe unsupported-option error. Existing no-options calls remain valid. Generates and stores all state/nonce/PKCE; URL returned only after durable transaction creation. No verifier exposed. |
| Public: `handleCallback` | Callback parameter multimap, protected browser context | `LoginResult { identity: DoxaIdentity }`, optional safe metadata | Section 12 errors. Owns state validation, atomic claim, exchange, full validation, optional configured enrichment and terminal cleanup. Returns no identity on authentication failure and establishes no local session by itself. |
| Public optional: `discover` | Configured issuer, optional bounded cache refresh | Validated immutable provider metadata | Discovery/capability/issuer errors; cannot alter trusted config. Useful for readiness checks; normal login invokes it internally. |
| Public optional: `getUserInfo` | Protected server credential/context from validated login, never arbitrary browser token | Matching-sub allowlisted enrichment | UserInfo failure. Must preserve issuer/client/sub provenance. May instead be internal to callback. |
| Internal: build authorization URL | Validated discovery + protected transaction + optional allowlisted options | Authorization URL | No security-parameter substitution, arbitrary parameter passthrough or endpoint overrides. |
| Internal: exchange code | Claimed transaction + opaque code | Protected token response | Token exchange failure. Exact stored redirect/verifier/client; one authentication method; no uncertain retry. |
| Internal: retrieve/cache JWKS | Validated issuer/JWKS context | Trusted compatible key set | Safe retrieval/validation error; finite cache, bounded refresh. |
| Internal: validate ID token | ID token + immutable transaction + trusted keys/time | Validated claims with provenance | All section 7 checks mandatory; decoded claims never count as output. |
| Internal: extract/get identity | Validated claims + optional verified UserInfo | `DoxaIdentity` | Identity extraction failure; immutable issuer/subject and typed optional fields. |
| Internal: store/claim/finish transaction | Protected attempt + browser binding | Persisted/claimed/terminal state | Expiry/replay/state/PKCE or safe storage error; atomic single claimant. |

`beginLogin` and `handleCallback` are the required login entry points; construct/configure is required setup. Low-level exchange/validation/build methods need not be public. If exposed for advanced server use, they MUST require the same protected context and MUST NOT offer a bypass around transaction or validation guarantees. Identity is returned directly; applications need not call a separate unsafe decode/get-identity method.

No required refresh, logout, revoke, client-registration or IAM administration API is introduced for baseline identity-only login. V1.3 defines refresh and credential deletion as obligations of the optional managed capability, not changes to existing login APIs. Optional credential use must follow sections 10 and 19–26 and remain separate from the minimal public identity result. Section 14's result notation is conceptual: existing ecosystem return types (including Laravel's direct `DoxaIdentity`) MUST remain compatible.

## 15. Responsibility matrix

| Responsibility | Doxa SDK | Host application | Doxa IAM |
| --- | --- | --- | --- |
| Trusted issuer/client configuration | Validate/use | Supply registered config and protect deployment secrets | Provide stable issuer and registration |
| Login button, callback route, browser redirects | Supply protocol results/framework adapter | Own UI/routes and invoke SDK | Own central login/consent UI |
| Discovery, endpoint selection, JWKS caching | Own consumer mechanics/validation | Supply infrastructure as needed | Publish metadata/public keys |
| State, nonce, PKCE, authorization transaction | Generate, protect, validate, consume | Supply protected browser/storage integration | Enforce S256 and bind nonce/code on provider side |
| Code exchange and ID-token validation | Own all client protocol/security checks | Handle safe result/error | Validate exchange, issue tokens |
| Central authentication/identity | Verify provider proof | Consume validated identity | Own credentials, central identity and lifecycle |
| Central application access | Handle success/denial | Arrange registration/assignment administratively | Evaluate current user/application/assignment |
| Optional UserInfo | Fetch safely, require matching subject | Decide whether enrichment needed | Return current scope-gated claims |
| `(issuer, subject)` local mapping | Return stable pair unchanged | Own lookup/uniqueness/linking/provisioning | Preserve issuer/public subject |
| Local users, employees, companies/business records | No ownership or automatic creation | Own policy and records | No downstream provisioning under this contract |
| Local roles, permissions and business authorization | No ownership/assignment | Own and enforce | Central assignment does not supply local permissions |
| Local session | Only explicit framework adapter under host policy | Own lifecycle, eligibility and authentication credential | IAM session remains separate |
| Local logout/session revocation policy | No implicit policy; future protocol adapter | Own local logout and central-change response | No downstream termination guarantee currently |
| IAM administration/client registration | Outside SDK | Authorized operational setup | Own admin APIs and authorization |

## 16. Multi-SDK architecture

All implementations conform to the same transaction, protocol, identity, errors and acceptance requirements. They may use ecosystem libraries, storage adapters and framework wrappers without changing trust boundaries.

| Intended implementation | Position |
| --- | --- |
| Laravel / PHP | First subsequent implementation, with HRM as first pilot. Framework adapter may integrate callback/session under explicit host policy. |
| Node.js / TypeScript | Likely second implementation for Node/Next.js server integrations. Browser client components must not receive confidential credentials. |
| Python | Independently implementable with Python OIDC/JOSE and server framework adapters. |
| .NET | Independently implementable with .NET OIDC/JOSE and server framework adapters. |
| Java | Independently implementable with Java OIDC/JOSE and server framework adapters. |

No universal requirement depends on Laravel middleware, guards, Eloquent models, PHP interfaces, Next.js sessions or a shared package binary. Maintain language-neutral conformance cases and equivalent observable behavior; sharing fixtures is useful, sharing implementation code is optional. This milestone creates no SDK package, HRM route, client registration or downstream mapping.

## 17. Versioning

Use a simple major/minor contract version, independently of each SDK package version. SDK releases declare the contract version they implement. Backward-compatible optional fields, safe optional claims, clarification and new ecosystem implementations may use minor revisions. Removing/changing fields, identity semantics or required behavior needs a new major version and migration guidance.

Unknown optional claims do not change identity or grant authorization. New OIDC features are explicit opt-in capabilities until added to the required baseline. Security fixes must not introduce a validation bypass to preserve compatibility; document any compatibility impact. Do not reinterpret existing mappings silently when issuer/subject changes.

## 18. Future extensions and decisions

Extensions may define logout, revocation, key-overlap rotation, additional optional claims, or other OIDC capabilities only after verifying IAM implements them. V1.3 now specifies optional client-side refresh consumption of the existing IAM behavior inventoried in section 2; it changes no provider grant or endpoint behavior. Current discovery advertises neither end-session nor revocation endpoints. Existing IAM password-session logout/client administration is not equivalent to universal OIDC logout/revocation support.

Product/deployment decisions still required before a production pilot:

| Decision | Current contract boundary |
| --- | --- |
| Production issuer and routing | Select/freeze exact issuer, HTTPS and any base-path routing. Discovery/base-path unit tests do not prove deployment reachability. |
| Pilot client registration | Choose confidential/public registration and exact callback URLs. Server pilot should use a confidential client plus S256; registration is a later milestone. |
| Unmapped/disabled local accounts | HRM explicitly decides linking/provisioning/local eligibility; default is no automatic account creation, activation or email linking. |
| Central lifecycle vs existing local sessions | Choose session lifetime and any recheck/notification/termination policy separately; no immediate cross-app revocation promise. |
| Refresh retention/use | Decide whether pilot needs IAM API access beyond login. Minimal login discards unneeded tokens; refresh is not identity renewal. |
| Key rotation and subject preservation | Operational rules for overlap, issuer stability and UUID preservation; model immutability guard is absent and not added here. |

### Historical verification of the v1.0 artifact

The final document was reviewed against the source inventory in section 2, including installed Passport/League behavior and existing discovery/JWKS/ID-token/UserInfo, authorization, issuance and redemption tests. Mandatory SDK-side state, transaction and validation protections are future SDK obligations, not claims of IAM enforcement. The universal API and storage abstractions have no framework dependency; `(issuer, subject)` remains the identity key throughout, UserInfo remains optional, and local authorization remains application-owned. No runtime code, schema, other documentation, client registration or downstream application is changed by this artifact.

Existing targeted tests were run from `backend` using `php vendor/bin/phpunit --filter 'OidcDiscoveryTest|OidcJwksTest|OidcIdTokenTest|OidcUserInfoTest|OAuthAuthorizationTest|OAuthIssuanceHardeningTest'`: **150 tests passed, 1,428 assertions**. PostgreSQL-only concurrent redemption tests were inspected but not run; real database race behavior and production endpoint reachability are not claimed as verified by this run. Document checks confirmed 18 numbered sections, 20 acceptance requirements, balanced code fences and no trailing whitespace.

### Contract acceptance requirements for every SDK

These are conformance requirements; each SDK records its own test evidence. A conforming implementation MUST demonstrate:

| ID | Acceptance requirement |
| --- | --- |
| AC01 | Discover endpoints from trusted issuer; reject discovery issuer mismatch, unsafe endpoints, malformed metadata and unavailable required capabilities. Request Host/callback input cannot change issuer/endpoints. |
| AC02 | Start login with mandatory `openid`, code/query, independently random state/nonce/verifier and canonical S256 for public and confidential clients. Reject PKCE disable/plain downgrade and unsupported scopes such as current `offline_access`. |
| AC03 | Protect server-side transaction state, nonce, verifier, timestamp/expiry, redirect, client, scopes, issuer and browser binding. Substitution of any security context fails without identity. |
| AC04 | Reject absent/mismatched/unknown state, another browser's state and ambiguous/duplicate security callback parameters before token exchange. Do not consume unrelated transactions. |
| AC05 | Reject expired/replayed transactions; concurrent callbacks allow only one exchange. Concurrent independent attempts remain isolated. Terminal errors/uncertain outcomes cannot reopen a transaction. |
| AC06 | Exchange code with exact stored redirect URI, client context and verifier. Missing/corrupt verifier fails locally; provider PKCE mismatch fails without identity. Altered redirect/client/code fails. No fallback method or automatic uncertain code retry. |
| AC07 | Reject a modified ID token, wrong signature, wrong key, algorithm confusion, `none`, non-RS256 algorithm and unavailable trusted key. Decoding alone never returns identity. |
| AC08 | Reject invalid issuer, wrong audience and invalid/missing `azp` when required for multiple audiences. Single current Doxa client audience works without `azp`. |
| AC09 | Reject expired ID token, missing/invalid required time claims, excessive future `iat` and invalid temporal ordering under documented bounded skew. |
| AC10 | Reject missing/mismatched nonce and missing/empty/non-string subject; nonce comes exclusively from protected transaction. |
| AC11 | Use issuer + subject as identity key. Email change preserves the key; same email with a different subject or issuer does not merge identities. Never substitute email/internal numeric ID for subject. |
| AC12 | Correctly extract scope-gated optional claims and boolean false; do not require optional profile/email fields, expose excluded claims or interpret raw claims as local permissions. |
| AC13 | Succeed without calling UserInfo when enrichment is unnecessary. If used, send bearer access token only, require `openid` provenance and exact matching UserInfo sub, preserve issuer/subject and discard mismatched response. |
| AC14 | Cache discovery/JWKS with finite issuer-isolated bounds; unknown `kid` triggers at most one bounded refresh per validation, with rate limiting. Expired/unavailable keys never disable validation. |
| AC15 | Confidential Basic/post and public none authentication use one configured compatible method with mandatory S256. Missing/wrong secret never downgrades to public auth. |
| AC16 | No codes, secrets, tokens, verifier, correlation secrets or sensitive claims appear in errors/logs; no IAM credentials in application-facing identity serialization, browser JavaScript or URLs. Callback URLs/codes are redacted. |
| AC17 | Return no identity for token error/missing ID token or failed mandatory validation; map errors safely without exposing provider bodies or falsely identifying a generic `invalid_grant` cause. |
| AC18 | No automatic employee/business-record/account/membership creation, email linking, activation, role assignment or application authorization. Host policy remains explicit. |
| AC19 | Do not create a local session or use an IAM access token as local credential implicitly. An explicit framework session adapter obeys host eligibility/policy. |
| AC20 | Current refresh response without ID token cannot produce a new validated login identity; do not promise local-session termination from IAM logout/access changes. |
| AC21 | No-options/default-options login preserves the authorization request; explicit reauthentication adds exactly one RFC 3986-encoded `prompt=login`. Reject unsupported/arbitrary prompt values and parameter injection before redirect. Browser/callback input cannot override trusted options or any security parameter. Preserve every transaction and validation guarantee. `login` is not account selection; `select_account` remains unsupported until IAM/provider support and an explicit contract extension exist. |

The following criteria apply when an SDK declares support for v1.3 managed credentials; AC01–AC21 remain mandatory and unchanged for every login flow.

| ID | Managed-capability acceptance requirement |
| --- | --- |
| AC22 | Default/legacy login retains no credentials and preserves existing signatures/return types. Managed retention requires trusted opt-in before authorization; changing callback query parameters cannot enable retention or alter the stored policy. The `pending_link` purpose and protected linking bindings must also be selected before authorization; callback/browser input cannot choose them. |
| AC23 | Managed callback performs all existing validation and configured enrichment, then returns identity plus opaque context only after durable protected persistence. Inject persistence failure: return no managed success/identity fallback, consume the transaction, clean abandoned records, and never repeat the code exchange. |
| AC24 | Identity fields/accessors/serialization remain unchanged. Access/refresh/ID tokens, raw responses, codes, secrets and PKCE verifiers never appear in identity or context serialization, browser output, logs, exceptions or debugging. No public token-dump or refresh-token getter exists. |
| AC25 | Persistent credential storage encrypts at rest through host-approved key management, enforces owner/issuer/client/subject boundaries, and stays separate from the ten-minute transaction store. Missing encryption, durable storage or distributed atomic capability rejects managed opt-in before redirect. Pending-link initiator, session, transaction, immutable target and expiry bindings receive the same storage/integrity protection. |
| AC26 | Substituting an opaque identifier, local owner/session, issuer, client or subject rejects retrieval/use without an IAM request. Scope/grant/expiry/version bindings are protected; IAM subject is never interpreted as a local user ID. Ordinary unbound contexts cannot make resource calls. A purpose-bound `pending_link` context permits only `checkApplicationAccess` under section 19.3; substituted initiator, linking transaction or target account fails. Completed contexts cannot transfer across local users. |
| AC27 | Valid access credentials are reused; expiry/safety-margin boundary causes refresh only for a needed Doxa operation. No idle timer refresh occurs; absent/expired refresh credentials require fresh authorization once access is unusable. Unknown expiry is never treated as indefinite validity. |
| AC28 | Refresh uses the trusted HTTPS token endpoint, one registered authentication method and original or narrower scopes. Malformed responses, expansion, changed binding or unexpected ID tokens fail safely; no refresh produces a new identity/local session or requests unsupported `offline_access`. |
| AC29 | Successful Doxa refresh requires a new rotated refresh credential, atomically replaces the access/refresh pair and expiry/scope state, increments version, and invalidates the old values. A missing/unchanged rotated token never permits fallback or reuse of the old refresh credential. |
| AC30 | Two independent workers/hosts refreshing one version perform at most one token request. The waiter reloads after claim, reuses the new usable access token, and a stale/fenced worker cannot overwrite newer credentials or send the old refresh token. |
| AC31 | Simulate response loss, worker death after dispatch, lease loss and rotated-response persistence failure: unresolved attempts become uncertain/unusable, never resend a potentially consumed refresh token, and require fresh authorization unless safe recovery of that same response is proven. |
| AC32 | Terminal invalid/revoked/expired grant or client failure stops retries, invalidates usability and removes secret material. Store failures block use and eventual cleanup remains durable; logout/deletion concurrent with refresh cannot resurrect a context. |
| AC33 | Safe categorized errors distinguish transient resource failure, ambiguous denial, incompatible context and credential lifecycle failure without provider bodies or invented invalid-grant reasons. A transient error never yields effective access or unsafe refresh retry. |
| AC34 | `checkApplicationAccess` calls only trusted `GET /oauth/application-access` with an internal user-delegated access credential and `openid`; no admin credential, selectors, arbitrary URL/body/query or raw-token getter is needed. Redirects cannot forward credentials. Before mapping, only a live, purpose-bound pending-link context may invoke this fixed operation; arbitrary resource calls and credential export remain prohibited. |
| AC35 | Only validated HTTP 200 minimal boolean `effective=true` confirms access. Current 403 yields ambiguous access-denied-or-insufficient-scope, not a fabricated cause; 401, malformed success, timeout and lifecycle failures never yield true. Decisions are not cached as continuing authorization. Pending-link confirmation without a fresh successful check creates no mapping. |
| AC36 | Host-bound logout/account unlink/replacement/security-event deletion blocks further context use, erases stored credentials and fences in-flight refresh. Local sessions stay host-owned; refreshed tokens or past access checks never imply ongoing IAM eligibility or automatic local-session renewal. Pending-link cancellation, expiry, conflict or mapping/binding failure consumes the linking attempt and destroys its context; concurrent refresh cannot revive it. |
| AC37 | Explicit deletion is idempotent; cleanup expires abandoned/unbound, terminal and lifecycle-expired contexts without racing live refresh into reuse. Recovering a crashed in-flight attempt does not reopen its token version for exchange. Verify pending-link confirmation claims, initiator/session/target/conflict checks, exact mapping and completion, and rollback plus context destruction on final mapping/binding failure. |
| AC38 | Equivalent conformance cases apply to PHP, Node/TypeScript, Python, .NET and Java. Framework adapters supply encryption/shared locks/session hooks without changing public identity semantics or the distributed safety guarantees. |

## 19. Managed credentials: identity, ownership and opt-in

### 19.1 Separate identity and credential context

`DoxaIdentity` remains exactly the normalized verified identity in section 8. It MUST NOT expose access tokens, refresh tokens, ID tokens, raw token responses, client secrets, PKCE verifiers or authorization codes through properties, getters, serialization or diagnostics. Neither refresh nor credential retrieval creates or revalidates an OIDC login identity.

An opaque `CredentialContext` is a server-side reference to a protected credential record created only by a successfully validated authorization transaction. Its identifier MUST be unguessable (at least 256 bits of CSPRNG entropy), not a token, JWT containing credentials or browser-controlled credential object. The identifier alone MUST NOT authorize retrieval/use; trusted host ownership and session/purpose bindings are checked independently. Contexts MUST NOT be returned to browser JavaScript, browser storage/cookies or public JSON. A normal local-session cookie may resolve to a server-side context association; it MUST NOT carry the context as a transferable credential capability.

The protected record binds these values directly or through integrity-protected server records:

| Binding/state | Requirement |
| --- | --- |
| Context identifier and provenance | Unique opaque identifier, originating successful transaction reference and validation completion time; no retained code/nonce/verifier or raw ID token required. |
| Trusted issuer and provider | Exact verified issuer, trusted endpoint context and registered OAuth client ID/authentication method; a protected reference to client-secret configuration, not a public secret field. |
| Verified subject | Exact subject from the fully validated ID token; immutable for this context. |
| Authorization boundary | Actually granted scopes, original scope ceiling, user-delegated grant provenance and client-bound application boundary. Provider grant identifier only if supplied authoritatively; otherwise an SDK-local lineage identifier MUST NOT be presented as an IAM grant ID. |
| Credentials and expiry | Encrypted access token and optional refresh token; token type, issuance/receipt time, usable access expiry, refresh expiry when authoritative, absolute context lifetime and abandonment deadline. Unknown refresh expiry is explicit, never invented. |
| Lifecycle/concurrency | Status, monotonically increasing credential version, refresh attempt/fencing identifier and durable dispatch state, created/updated timestamps, deletion/terminal state and sanitized reason category. |
| Host ownership | Protected initiating browser/login binding; subsequently trusted local account ID and permitted local session/purpose binding. Local IDs belong to the host namespace, separate from `(issuer, subject)`. |

The host resolves `(identity.issuer, identity.subject)` under existing local-account policy and checks eligibility; no identity linking is implemented here. An ordinary unbound context has a short, documented host-configured lifetime and is usable only for protected host association, not IAM resource requests. The sole pre-mapping exception is the purpose-bound `pending_link` context in section 19.3, which permits only the fixed application-access check. The host MUST bind the context atomically to the selected local account/session after successful mapping, carrying forward the initiating login binding. Session regeneration may migrate that binding only through trusted server-side host integration. Mapping failure, local denial or failure to bind MUST destroy the context; abandoned contexts are cleaned up. Another user's identifier, shared email or browser-supplied owner cannot substitute. Association MUST NOT silently transfer ownership or merge accounts.

The local session authenticates a local user under host policy; `DoxaIdentity` identifies the verified federated `(issuer, subject)` mapped to that user. The credential context belongs to that host association and references one IAM grant lineage. The access token authorizes scoped IAM resource calls; the refresh token renews that grant's credentials without renewing the local session or identity proof. Multiple local sessions/contexts require explicit host ownership and deletion policy; no cross-session reuse is implied. A caller failing ownership checks MUST NOT use, delete or invalidate another owner's context merely by supplying its identifier.

### 19.2 Explicit retention and callback compatibility

Baseline `beginLogin()` and `handleCallback()` keep their established public behavior and ecosystem return types, including Laravel's identity-only callback. No-options/default typed authorization options, `prompt=login`, all transaction protections and optional UserInfo behavior remain intact. Identity-only callbacks discard access/refresh credentials after required use; every login need not retain them.

Managed retention MUST be selected by trusted host code before returning the authorization URL, through a separately proposed flow or typed managed-login policy. Persist an immutable `identity_only` or `managed` policy and intended host association/purpose in the protected OAuth transaction. Absence means identity-only. Callback query parameters MUST NOT select/change the policy; calling a managed callback entry point for an identity-only transaction MUST reject, not upgrade it. A mismatched identity-only handler for a managed transaction MUST fail safely rather than silently dropping required persistence. Retention is distinct from section 6.1's authorization options: `prompt` still changes only the outgoing request and need not be stored; managed policy adds its own protected transaction fields and never becomes an OAuth parameter.

Managed configuration MUST be checked before redirect: required secure credential storage, encryption, owner binding, refresh capability when needed, distributed atomic semantics and lifecycle policy must be available. After the existing one-time callback claim, exchange, complete ID-token validation and configured UserInfo processing, create the durable protected credential record. Return a separate managed result containing the unchanged identity and opaque context only after persistence is confirmed. Retain only necessary credential fields; discard the code, verifier, ID token and raw response. No raw credentials reach the browser or ordinary result serialization.

If persistence fails or its result is uncertain, the managed callback MUST fail with a safe storage/lifecycle error and no successful managed result or automatic identity-only fallback. The authorization transaction stays terminal; no repeated code exchange. In-memory secrets are discarded, any partial/orphan record is unusable and scheduled for deletion by its transaction provenance/unbound deadline. An orphan handle is never exposed. Provider-side revocation is not promised: local deletion does not undo issuance. An existing unrelated host session is governed by host policy, not automatically changed by this failure.

### 19.3 Narrow pending-link purpose and confirmation boundary

A host may explicitly opt into managed credentials with purpose `pending_link` before authorization. After complete OIDC validation, the host keeps the unchanged verified `DoxaIdentity` in a protected pending-link transaction. The credential context MUST bind trusted server-side records for the validated issuer/client/subject, initiating HRM browser/session, authenticated HRM administrator or equivalent authorized initiator, specific linking transaction, intended local HRM account identifier in the protected host namespace, and a short documented expiry and terminal lifecycle. The initiator and target account are distinct bindings; neither IAM subject nor administrator identity substitutes for the target. The context identifier alone authorizes nothing. Purpose/target/initiator cannot be selected or substituted by callback input.

Before mapping, this context permits ONLY the high-level `checkApplicationAccess` operation against its configured Doxa issuer and own authenticated client/application. It does not permit arbitrary IAM resource calls, caller-supplied endpoints/subjects/application selectors, credential export, account transfer or browser credential access. This is a purpose-bound exception, not an ordinary unbound context. Internal lazy refresh solely to complete that allowed check retains every requirement in sections 21–23; it MUST recheck the live linking transaction, purpose, session/initiator, immutable target and expiry before dispatch and conditional persistence, preserve pending purpose, and never extend its deadline or resurrect terminal state. It is not a separate pre-mapping refresh/resource API.

At explicit linking confirmation, the host MUST:

1. Atomically claim the pending-link transaction for one confirmer; concurrent or replayed confirmations cannot create another mapping.
2. Recheck expiry, session/authorized-initiator binding, target-account eligibility and identity conflicts using protected records.
3. Perform a fresh application-access check with this context as close as practical to mapping creation. An unavailable, invalid, denied, insufficiently scoped or ambiguous-403 result MUST NOT create a mapping.
4. After successful verification, recheck local eligibility/conflicts under local concurrency protection and create the exact `(issuer, subject)` ExternalIdentity mapping for the immutable target, atomically with completed linking state where practical.
5. Bind the retained context to that completed target-account/session association under normal owner/lifecycle policy. This one-time purpose transition MUST verify the claimed linking transaction and exact mapping; it is not transfer from the initiating administrator to an arbitrary user.
6. If final mapping or binding fails, roll back the local transaction and destroy the pending-link context. Coordinate binding with local commit; when the credential store cannot join that transaction, stage/fence binding so no completed context is usable before confirmed local commit, and invalidate it on rollback or uncertain completion.
7. Cancellation, expiry or conflict MUST consume the linking transaction and destroy the context. Any failed confirmation terminates this attempt; in-flight refresh or a late successful check cannot reopen it. A new attempt requires fresh authorization.

The IAM check and HRM database update are NOT one atomic distributed operation. Verification remains point-in-time and can race IAM state changes before the local commit. Hosts own explicit confirmation, conflict handling, transaction coordination and mapping; this contract implements no identity linking. Once completion/binding is committed, SDK operations require the normal completed owner/session and lifecycle policy, and cannot transfer the context across local users.

## 20. Language-neutral credential-store contract

The store is separate from the ten-minute authorization-transaction store, even if one physical storage service hosts distinct namespaces. Durable storage must survive the intended session/account lifecycle, worker restarts and failover without resurrecting obsolete credentials. Process-local memory or locks alone are insufficient for multi-worker/multi-host deployments.

| Conceptual store operation | Required observable semantics |
| --- | --- |
| Create | Persist encrypted credentials and protected bindings under a unique identifier; atomic creation with idempotent transaction provenance prevents duplicate orphan records. Confirm durability before returning a context. |
| Retrieve | Resolve opaque identifier with trusted owner/session/purpose and issuer/client binding; check lifecycle/version/integrity before releasing data internally. Never expose secrets to public serialization. |
| Bind owner | Atomic compare-and-set from the initiating unbound login, or the verified claimed pending-link completion, to the permitted immutable target account/session; forbid cross-user reassignment and prevent usability before local commit. |
| Claim refresh | Shared atomic claim for a record/version, durable attempt and dispatch state, and fencing token. Coordinate every host; reload record after claim. Bound waiting/lease behavior cannot permit a second dispatch for an unresolved attempt. |
| Replace rotated pair | Atomic compare-and-set on context, version, attempt/fence, expected live status and owner binding; replace access/refresh tokens, expiry and scopes together and increment version. Reject obsolete writes. |
| Mark lifecycle | Atomically mark expired, revoked, uncertain or unusable and fence subsequent use/refresh. Terminal transitions cannot be undone by stale workers. |
| Delete | Idempotently make the context unusable, erase credential material, and retain only a bounded nonsensitive tombstone needed to fence in-flight operations/replay. |
| Cleanup/recover | Enumerate due expired/abandoned/terminal records and unresolved attempts under atomic checks; erase secrets and expire tombstones without reopening an old token version. |

Status includes unbound, active, refreshing, access-expired/refreshable, expired, revoked, uncertain, unusable and deleted. Implementations may use equivalent states, but access expiry alone is not terminal when a usable refresh credential exists. Uncertain is nonusable; successful refresh restores active only from its own valid in-flight attempt. No arbitrary transition can revive a terminal context.

`pending_link` is a protected purpose, not permission inferred from an unbound status. Retrieval/use requires all section 19.3 bindings and its fixed-operation allowlist. Claims, rotated-pair replacement, purpose completion and cleanup MUST condition on the live linking lifecycle and immutable target as well as credential version/fence. Cancellation/expiry/conflict must fence refresh and erase credentials; cleanup or refresh cannot restore a terminal link or promote pending purpose to ordinary use.

The SDK owns protocol validation, immutable bindings, scoped internal use, expiry decisions, refresh state machine, fencing checks, safe errors and cleanup triggers. The host adapter owns durable encrypted persistence, atomic/shared coordination, access controls, approved key management/rotation, operational expiry/cleanup scheduling, deployment availability and deletion guarantees. Encryption may occur in the SDK adapter or storage layer; the host MUST approve its key-management mechanism. Protect metadata against tampering as well as confidentiality; keys MUST NOT be co-exposed with ciphertext to unauthorized readers. Key rotation/decryption must preserve integrity and must not fall back to plaintext. Backups, replicas and diagnostics require equivalent credential protection and a documented retention/deletion policy.

Adapters MUST enforce isolation across local users, clients and issuers and reject missing ownership. SDK code MUST NOT treat an adapter's generic `get/set` or local mutex as proof of sufficient capability. If secure durable storage, encryption/decryption, atomic refresh/update or owner enforcement is missing/unavailable, managed operations fail closed; identity-only login remains available without that adapter. Lost/evicted records require reauthorization, never reconstruction from browser input.

## 21. Token lifecycle and lazy refresh

### 21.1 Initial authorization and usable expiry

All sections 4–9 validation remains required. Validate a bounded token response, nonempty access token, supported `Bearer` token type, positive finite `expires_in`, any refresh token's nonempty string type and the complete OIDC result before persistence. Granted scopes come from a valid response scope set, or the requested scope set when omission has OAuth-defined same-scope semantics; they MUST NOT exceed the protected requested scopes. Required `openid` provenance must be retained. Tokens are opaque credentials: do not apply ID-token claims rules to Doxa access/refresh tokens, decode encrypted refresh tokens, or infer bindings from unvalidated JWT claims. Bind by trusted exchange provenance and verified identity instead.

Calculate conservative absolute access expiry from request start plus validated lifetime (or an earlier authoritative expiry when supported), not response receipt plus latency. Record the chosen calculation. Each SDK MUST document a safety margin and clock policy: default 60-second early refresh margin, configurable within 0–60 seconds; never extend actual access expiry with positive skew. This is separate from section 7's bounded ID-token validation skew. Tokens at/past the usable boundary must be refreshed or rejected; a lifetime too short for the margin is not silently considered usable. Invalid/unknown access expiry cannot be persisted as a usable indefinite credential.

Only explicitly managed callbacks persist credentials. If no refresh token is issued, store an access-only context with no invented refresh token or `offline_access` request. It remains usable until its access safety boundary/context lifetime; then require fresh authorization. Doxa's inspected refresh lifetime is seven days, but discovery/response does not necessarily expose refresh expiry. Store an authoritative refresh expiry if provided; otherwise mark it unknown and impose a finite host-approved context lifetime. A configured conservative lifetime is a local limit, not a claimed provider expiry. Rotation MUST NOT extend the absolute host context lifetime or local session automatically.

### 21.2 Internal access-token use

SDK operations accept the opaque context and trusted host invocation binding. They check owner/session, issuer/client, grant type, authorized scopes, status and absolute context lifetime before internal token acquisition. The access token MUST be used only in an Authorization header for an explicitly supported server-side IAM operation at its trusted provider endpoint; no arbitrary URL/proxy or unrestricted raw-token getter is introduced. Disable credential-forwarding redirects; verify TLS, bound timeout/response size and redact transport instrumentation. A local expiry check cannot establish absence of provider revocation or current application eligibility.

Readers MUST observe a consistent credential version, not a partially rotated pair. While a refresh attempt is unresolved, other workers wait within a bounded limit or return a safe busy/transient error; they MUST NOT use its old pair because IAM rotation revokes the previous access token too. A request already dispatched before rotation can race revocation and fail; it never implies continuing access and cannot authorize a false result. Deletion fences further acquisition; an already dispatched request cannot be remotely recalled and its result MUST NOT re-enable a deleted context.

### 21.3 Lazy refresh and response validation

Refresh only when an authorized SDK operation needs a token and no usable access token remains under the expiry margin. No periodic idle refresh or automatic local-session renewal. Require an active bounded context, a nonterminal refresh credential and a trusted provider advertising refresh capability. Use the validated HTTPS token endpoint and one configured client authentication method matching registration (public `none`, confidential Basic or post); no downgrade, cookies, redirects or credential logging. Development transport exceptions for baseline login do not relax this managed refresh HTTPS requirement.

Check any authoritative stored refresh expiry before dispatch; an expired refresh credential MUST NOT be submitted. Apply a documented conservative clock margin without extending that expiry. Unknown provider refresh expiry is governed by the finite local context lifetime and eventual provider rejection, not a fabricated validity guarantee.

Send `grant_type=refresh_token` internally with the claimed stored refresh token and bound client authentication. Omit scope to preserve the grant or explicitly request the same/narrower granted scopes under trusted policy; never request new scopes, `offline_access` or a different application. Validate bounded successful response shape, `Bearer`, nonempty access token, positive finite expiry and returned scope subset of the prior grant and original ceiling. Omitted scope inherits the effective requested/prior set under OAuth semantics. Narrowed scope is persisted and never restored implicitly. New issuer/client/subject metadata, if present, must match the immutable binding. Refresh has no nonce/code transaction and MUST NOT create a new identity. Doxa refresh returns no ID token; an unexpected ID token is a contract violation, not fresh authentication.

The Doxa refresh contract guarantees rotation: require a nonempty new refresh token distinct from the old value in every successful refresh. Missing/malformed/unchanged rotated credentials are a protocol/lifecycle failure, never an optional field or a reason to reuse the old token. Atomically persist the complete new pair and expiry/scope state before returning internal usability; remove/invalidate the stored old pair and never submit the old refresh token again after success. Discard obsolete in-memory copies; crash recovery cannot replay them. If validation or persistence fails after a possibly successful provider exchange, treat the outcome as unusable/uncertain, not a successful refresh.

Token refresh does not itself establish that application access remains effective at the time of a later operation. Although IAM currently rechecks issuance eligibility, only a successful authoritative resource/access check proves its own point-in-time decision. It never grants local roles or proves local account eligibility.

## 22. Distributed concurrency and uncertain refresh outcomes

A conforming strategy combines a shared atomic record claim with monotonic versions/fencing and durable in-flight state. A database transaction/row lock or distributed lock plus conditional updates may supply this behavior; a process-local mutex or expiring lease without durable dispatch protection cannot.

1. Retrieve with trusted owner binding and check usability. If refresh appears necessary, acquire the shared record claim with bounded waiting. After acquiring it, reload the latest record and recheck owner, lifecycle, expiry and version. If another worker already produced a usable token, reuse it without refresh.
2. Atomically claim the current version and persist a unique attempt/fence with in-flight state before dispatch. At most one worker may dispatch refresh for that version. Mark dispatch intent durably before sending: a crash between marking and actual send is conservatively uncertain unless non-dispatch can be proven. No worker may submit an old token on an unresolved attempt.
3. Validate the one response. Commit rotated pair via compare-and-set requiring the same live context/version/attempt/fence; increment version and finish the attempt atomically. A waiter reloads this committed record. Late/stale workers cannot overwrite newer credentials, revive deletion or clear a newer attempt.
4. Lease expiry/lock loss is not permission to redispatch. Recovery observes the durable unresolved attempt. Unless authoritative safe recovery of that exact attempt is proven, atomically mark uncertain/unusable and require a new authorization transaction. Provider database locks alone do not solve response loss or local-store races.

A timeout/reset after dispatch, worker crash, lost response or store failure after successful provider rotation may mean IAM consumed the refresh token. MUST NOT blindly retry it, even after backoff or on another host. No idempotent refresh/recovery endpoint or token-family recovery guarantee is established by current IAM. Safe recovery can only commit the same already validated rotated response while the original attempt/fence still owns the context, or use a future explicitly specified provider mechanism; it MUST NOT send the potentially consumed old token. If durability of a write is uncertain, reload/check the attempt/version before considering that same response committed; if proof is unavailable, block use and retire the context.

Retries are safe only when non-dispatch/non-consumption is proven, such as a local pre-send failure with durable evidence that no request bytes were sent. A timeout, generic 5xx, malformed response or proxy error after dispatch does not establish rollback, even though IAM's implementation has transaction rollback in some paths. Do not infer safe reuse from HTTP status alone. Marking uncertainty is a safe recovery path requiring fresh authorization, not successful refresh.

## 23. Failures, retry policy and cleanup

Expose sanitized categories/results, not provider bodies, internals or token values. A generic `invalid_grant` MUST NOT be relabelled as a known user/application/assignment cause. Classification depends on the operation and on proven dispatch state, not just the status code.

| Condition | Category and required safe behavior |
| --- | --- |
| Expired/near-expiry access token | Refresh lazily if safely possible; otherwise `reauthorization_required`, retire expired access-only context. Never send a known unusable token as successful proof. |
| Absent refresh token | Not an initial-login failure; access-only until usable boundary. No refresh attempt/invention; then terminal context expiry and fresh authorization. |
| Expired/revoked refresh token; `invalid_grant` | Terminal `credential_invalid`; invalidate/delete secrets and require reauthorization. No indefinite retry or invented precise cause. |
| Revoked OAuth client; invalid client authentication | Terminal `client_unusable`; invalidate context and stop refresh/use. Host must resolve registration/configuration before fresh authorization; do not downgrade auth. |
| Inactive/suspended/deleted IAM user; inactive/deleted application; revoked assignment | IAM may return generic resource 401/403 or issuance denial. Known terminal issuance/credential failure retires context. Current endpoint 403 is ambiguous (section 24): deny the local protected action, suspend context use under host policy and destroy it by default; no repeated refresh to bypass denial. Do not invent the lifecycle reason. |
| Insufficient scope or incompatible context | Local detected issuer/client/grant/scope/owner mismatch: `incompatible_context`, no network request; do not broaden scope or switch user/client. Provider 403: ambiguous denial. A context needing new scope requires explicit new authorization; default retire unusable-for-purpose context. |
| Resource HTTP 401 invalid/expired credential | `credential_invalid`; invalidate/delete context and require fresh authorization. No automatic 401-triggered refresh/replay against current IAM: generic 401 does not prove simple expiry. Normal expiry-driven refresh happens before dispatch. |
| Resource timeout or server error | `transient_service_failure`; no access decision. An unchanged still-live context may remain for a bounded retry of the read-only access GET, with backoff and all bindings/expiry rechecked. No false denial/true cache fallback. |
| Refresh pre-dispatch network failure | Transient only when non-dispatch is proven. Release/restore the claim safely under its fence for a bounded later retry; no usable expired access token or successful access decision returned. |
| Refresh timeout/server error after possible dispatch | `refresh_outcome_uncertain`; no retry of old token. Fence context, remove secrets and require fresh authorization unless same-attempt recovery is proven. |
| Malformed refresh response; missing/unchanged rotated refresh token; broadened scopes | `refresh_contract_violation`; potentially consumed old token is never reused. Context unusable, cleanup and fresh authorization; host may need provider/configuration remediation. |
| Concurrent refresh | Bounded wait then reload; return latest usable token or safe `credential_busy`/transient failure. No second dispatch for unresolved version and no stale overwrite. |
| Credential store unavailable | `credential_store_unavailable`; fail closed, no cached/raw fallback. Before dispatch a bounded storage retry may be safe; after possible refresh dispatch uncertainty applies. Persist invalidation/cleanup when store recovers; in-flight durable markers prevent reuse. |
| Decryption/integrity failure | `credential_store_integrity_failure`; no plaintext/key fallback. Quarantine/fence record, destroy unusable material under host policy and require reauthorization after key/storage remediation. Do not log ciphertext/plaintext or key data. |
| Uncertain refresh outcome | Nonusable terminal-for-use state; no old-token retry, no grant/identity success claim. Fence and delete material, retain only sanitized recovery/tombstone metadata. |

Terminal/uncertain credentials MUST become nonusable immediately at the accessible store boundary; erase credential material and discard in-memory copies. If the store is unavailable, deny operations and leave the durable in-flight fence effective; reconcile invalidation/deletion on recovery before any use. Cleanup is idempotent and bounded by documented host retention schedules, including unbound abandoned contexts, absolute lifetime expiry, deleted/terminal records and crashed attempts. Preserve only nonsensitive tombstones long enough to block stale workers; never archive obsolete refresh tokens for replay/debugging. Backups must not be restored into a usable obsolete token version.

`deleteCredentialContext` conceptually performs local invalidation/erasure, not provider revocation or IAM logout. Host logout, unlink, context replacement, account disable/security event and explicit deletion MUST invoke this lifecycle for affected contexts. Administrative provider revocation, where supported, remains a separately authorized host/operator action; the user SDK MUST NOT acquire central-admin credentials. No universal revocation/end-session endpoint is invented. An already sent token cannot be recalled; local deletion prevents subsequent SDK use and never promises immediate termination of every IAM/host session.

## 24. High-level application-access operation

The conceptual optional capability `checkApplicationAccess(credentialContext)` is a scoped SDK operation, not an arbitrary HTTP proxy. Invoke only from trusted host code with the completed context's owner/session binding, or all purpose-bound pending-link bindings in section 19.3. Internally acquire a usable access token under sections 19–23 and call the existing trusted `GET /oauth/application-access` endpoint with `Authorization: Bearer` and no body, query string, subject/application/client selectors or browser credential inputs. The context must represent user-delegated credentials with `openid`; no central-admin authorization/credential is required. A pending-link HRM administrator is a host-authorized initiator, not an IAM admin credential. The IAM derives identity and application from its validated credential and evaluates current eligibility; HRM and other hosts MUST NOT duplicate its access rules.

Endpoint selection comes from protected issuer-bound configuration or explicitly validated provider metadata. This optional nonstandard resource path is not a license to hard-code/override discovered OIDC token/UserInfo endpoints in section 4. Base-path deployment routing must be explicit; no discovery advertisement of this endpoint is assumed. Enforce provider origin binding, HTTPS, no credential-forwarding redirects, bounded response handling and no-store behavior. An ID token, client secret alone, IAM browser session or local identity object cannot authenticate this call.

| Conceptual outcome | Meaning and caller behavior |
| --- | --- |
| `effective` | Only HTTP 200 with the strictly validated minimal object `{"effective":true}` and boolean type confirms current access. No extra provider data returned. |
| `denied_or_insufficient_scope` | Current HTTP 403 fails authorization but does not provide a contract-guaranteed distinction between ineligibility and insufficient scope. Return no effective confirmation or invented reason; deny the protected action. |
| `credential_invalid` | HTTP 401 or terminal credential failure; no access decision, cleanup and fresh authorization. |
| `incompatible_context` | Locally missing `openid`, wrong grant/binding, absent/deleted context, ordinary unbound context, invalid pending-link bindings/lifecycle, or unsuitable client/issuer; no request and no access confirmation. Do not substitute selectors or credentials. |
| `transient_service_failure` | Timeout/network/5xx; authoritative answer unavailable. No true or cached continuing grant; bounded read-only retry may be allowed. |
| `credential_lifecycle_failure` | Refresh/storage/decryption/uncertain outcome prevents authenticated request; safe subcategory/retry guidance under section 23, no access confirmation. |
| `protocol_failure` | Malformed/oversized success, unexpected fields/types/status, redirect or request-contract rejection (including 400); fail closed without exposing raw response. |

An implementation may represent outcomes as tagged results or typed errors plus a minimal decision. Only confirmed effective maps to `effective=true`. A 403/error MUST NOT be silently converted to an authoritative boolean false with a claimed specific cause. No credential or internal denial data appears in the result. A validated `effective=false` may be supported only if a future provider contract explicitly enables it, preserving the v1.2 extension; today's endpoint guarantees only the positive body and 403 failure.

**403 protocol gap:** current endpoint uses 403 for access denial and insufficient scope. The endpoint documentation describes different error bodies/challenges, but this v1.3 contract deliberately does not guarantee callers can distinguish those causes. A caller requiring a distinct negative access decision versus missing scope needs an explicit, stable provider/API contract for discriminated machine-readable outcomes (and SDK validation/conformance cases), while retaining minimal disclosure. That is a separate protocol decision; no IAM change is made here. Until then return the combined outcome even when a response appears more specific.

Never cache this result as continuing authorization. It is a point-in-time answer, can race a subsequent local operation and does not set host roles/session eligibility. No successful authoritative answer means no `effective=true`; neither refresh success, decoded claims, prior login nor cached UserInfo is a substitute.

## 25. Proposed cross-language API design (not implemented)

These are candidate conceptual names, not final PHP signatures or claims of existing SDK APIs. Preserve all existing public APIs and `DoxaIdentity`; ecosystems may use idiomatic synchronous/asynchronous result or exception types.

| Proposed capability | Inputs/result and boundary |
| --- | --- |
| Existing `beginLogin` / `handleCallback` | Unchanged identity-only entry points and existing return types; typed authorization options still supported. No storage requirement for retained credentials. |
| Proposed `beginManagedLogin` | Trusted managed-retention policy, protected browser context and existing typed authorization options; records policy/purpose before redirect, including protected pending-link bindings when requested, and returns ecosystem-equivalent login start. |
| Proposed `handleManagedCallback` | Existing callback/browser inputs; requires a managed transaction and returns separate non-publicly-serializable `ManagedLoginResult { identity, credentialContext }` only after protected persistence. No token fields. |
| Proposed adapter `bindCredentialContext` | Protected initiating login plus host-selected account/session, or claimed pending-link completion for its immutable target; atomic association after exact mapping with local-commit coordination. Not a browser endpoint or an SDK identity-linking API. |
| Proposed `checkApplicationAccess` | Opaque completed context with trusted owner binding, or a purpose-bound pending-link context with section 19.3 bindings; minimal tagged outcome in section 24. No selectors/URL/token inputs; the only pre-mapping operation. |
| Internal `acquireUsableAccessCredential` / `withCredentialForOperation` | Owner-bound context and fixed supported operation; validates/reuses/refreshes internally, executes through SDK transport and never returns a raw token to ordinary application code. |
| Proposed `deleteCredentialContext` | Owner-authorized opaque context; idempotent local invalidation/erasure, fences refresh. Administrative cleanup uses separately authorized storage tooling, not bearer handle possession. |
| Proposed `cleanupCredentialContexts` | Optional protected operational adapter hook/job; bounded cleanup and crash recovery under section 23, no credential values returned. |

Separate managed entry points make retention and result shape explicit and avoid changing existing callback return types. An alternative is an immutable typed login policy on a compatible entry point plus a distinctly typed managed result handler; it risks ambiguous handler/policy pairing and must enforce stored policy, not callback parameters. Final ecosystem naming/overloads and adapter binding ergonomics remain proposals pending implementation design. All behavioral/security requirements above are settled regardless of naming; no public get-access-token/get-refresh-token/token-dump API is proposed.

PHP/Laravel, Node/TypeScript, Python, .NET and Java SDKs must enforce the same opaque ownership, lifecycle, rotation and distributed conformance behavior. Framework session hooks, databases/Redis stores, encryption/KMS providers, transaction/lock primitives and cleanup schedulers belong to adapters. For Laravel, future implementation must retain Laravel 10/11/12 compatibility; this document does not implement or retest that support. Node server code must keep contexts out of browser components; equivalent server-only boundaries apply to every language. Async/concurrency syntax never substitutes for shared atomic coordination.

## 26. V1.2 to v1.3 migration and remaining decisions

V1.1 authorization options and v1.2 current-access requirements remain, including exact issuer/subject mapping, no selectors, origin/redirect safety, safe minimal outputs and identity-only compatibility. AC01–AC21 are unchanged. V1.3 adds the managed transaction policy/result, opaque owner-bound credential context, encrypted persistent store, lazy refresh/mandatory rotation, shared concurrency/uncertainty handling, cleanup and AC22–AC38. It explicitly replaces the v1.2 prohibition on introducing refresh in that capability with this opt-in contract; refresh still cannot produce a validated login identity. Access-token expiry now triggers refresh where safely possible, while context/refresh lifetime expiry remains terminal. No existing security guarantee is relaxed.

Existing applications need no migration for identity-only login or typed `prompt=login`. SDK package versions and declared optional capability support remain separate from the universal contract version. Future SDK implementations must add the secure store/owner adapter, managed-flow APIs, response/lifecycle validation, distributed claim/update and recovery logic, access-check operation and conformance evidence before advertising v1.3 managed support. This document alone implements no refresh-token behavior, SDK methods, token endpoint changes, IAM revocation mechanism or HRM integration.

HRM would need to explicitly choose managed login in trusted code, configure approved encrypted durable storage/key management/shared atomic operations, and use the protected `pending_link` purpose when linking precedes local association. Keep the verified identity in its pending transaction, claim explicit confirmation, perform the fresh access check before exact mapping, then complete local mapping/state and credential binding under section 19.3. Ordinary login still maps the verified `(issuer, subject)` under existing policy and binds its context after mapping. Install logout/unlink/security-event/cancellation/deletion/cleanup hooks; handle denial/errors without granting access. Define local session/context lifetime independently, do not reproduce IAM eligibility rules, assume subject equals a local user ID, or auto-link by email. No HRM or SDK linking runtime is implemented here.

Remaining decisions and provider limits:

- Final per-language managed API names/result/binding integration and concrete storage adapters need implementation review; no PHP signatures are frozen here.
- Hosts must select key management, approved shared locking/atomic backend, finite context/unbound/tombstone lifetimes, cleanup schedules, bounded retry/wait limits and documented expiry margin. These adapter choices cannot weaken the contract.
- Refresh-token expiry is not assumed to be advertised; the seven-day source baseline is not a portable response field. Unknown expiry requires a finite conservative local lifetime, not token decoding or a new provider response requirement.
- No refresh idempotency/recovery or token-family-wide replay response is established. Uncertain dispatch requires fresh authorization; seamless recovery needs a separately agreed provider feature.
- No discovery advertisement for application-access, universal revocation or end-session endpoint is assumed. Trusted optional resource configuration/base-path routing and administrative revocation remain separate concerns.
- Current 403 cannot be relied on for a distinct denial-versus-scope outcome under this contract; section 24 records the documentation/protocol distinction and the future stable API agreement needed.
- IAM lifecycle propagation to existing host sessions, signing-key overlap and subject immutability limits in section 2.2 remain unresolved. Neither local credential deletion nor refresh promises cross-application logout.

This milestone modifies only this contract. It does not implement identity linking, SDK/IAM/HRM code, refresh backend behavior or tests, and does not stage, commit or push.
