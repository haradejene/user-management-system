# Doxa universal SDK contract

Contract version: **1.1**. Adds backward-compatible, allowlisted authorization options to v1.0. Authorization-options revision: **2026-10-07**. The original IAM inventory below retains its **2026-10-04** inspection baseline.

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

No required refresh, logout, revoke, client-registration or IAM administration API is introduced in v1. Optional token access must follow section 10 and remain separate from the minimal public identity result.

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

Extensions may define logout, revocation, access-token refresh, key-overlap rotation, additional optional claims, or other OIDC capabilities only after verifying IAM implements them. Current discovery advertises neither end-session nor revocation endpoints. Existing IAM password-session logout/client administration is not equivalent to universal OIDC logout/revocation support.

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
