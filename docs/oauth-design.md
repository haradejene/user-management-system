# OAuth 2.0 preparation

## Inspection and current state

The audit implementation was committed and pushed as `0ecceea` before this preparation.
The backend uses Laravel 12.68.0 on PHP 8.2.12. Passport was absent from both the installed
packages and composer.lock. The selected compatible release is Passport 13.8.0, constrained
to `~13.8.0`; Composer locks the exact dependency versions. Installation requires Sodium,
which was present but disabled in the local XAMPP PHP configuration.

Current authentication is a Laravel `web` session backed by the Eloquent User provider.
AuthService registers users, checks credentials and active status, regenerates sessions,
and invalidates them on logout. The Next.js frontend obtains `/sanctum/csrf-cookie`, then
sends credentials and CSRF headers with cookie-authenticated requests. Sanctum's stateful
middleware protects `/api/me` and administrative API routes through `auth:sanctum`.
The `active` and `central-iam-admin` middleware enforce IAM account/administrator policy.
User-to-application grants and company memberships are existing domain relationships.

The current CORS policy permits the IAM frontend and covers `api/*` and the Sanctum CSRF
endpoint. It does not yet configure browser clients for `/oauth/token`. No OAuth login
redirect continuation, authorization consent view, or downstream token validation exists.

## Roles and vocabulary

| Term | Meaning in this architecture |
| --- | --- |
| Authorization server | Central IAM, using Passport, authenticates the user and issues delegated authorization to registered clients. |
| OAuth client | HRM, CRM, or ERP software requesting permission to call a protected API on a user's behalf. The client is software, not the user. |
| Resource server | The API receiving and validating access tokens. IAM can host an OAuth-protected API; downstream APIs are also resource servers if they accept IAM-issued tokens. |
| Authorization code | A short-lived, single-use intermediate credential returned to the client callback and exchanged at the token endpoint. |
| Access token | A time-limited credential authorizing calls to a resource server with specified scope. It is not an ID token. |
| Refresh token | A credential sent only to the authorization server to obtain replacement tokens; never use it to call business APIs. |
| Redirect URI | The client's pre-registered callback address receiving the code. Require exact matching, HTTPS in production, and no wildcards. |
| PKCE | A per-attempt random verifier whose S256 hash goes in the authorization request. The verifier is checked during code exchange, protecting intercepted codes. |
| Scopes | Named API capabilities carried by a token. They do not replace account status, application assignment, company membership, or local business permissions. |
| Client registration | Administrator-controlled provisioning of a client ID, approved callbacks, client type and grant types, plus a secret only for confidential clients. |

Definitions follow [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749) and
[PKCE, RFC 7636](https://www.rfc-editor.org/rfc/rfc7636).

## Responsibilities

Passport provides OAuth protocol endpoints, client and token persistence, authorization-code
validation, PKCE verification, signed access-token issuance, refresh handling, token revocation
repositories, and Laravel bearer-token validation and scope middleware. The installed source
is the reference for exact behavior: `vendor/laravel/passport/src/PassportServiceProvider.php`,
`src/Passport.php`, `routes/web.php`, and the League OAuth server implementation.

IAM remains responsible for the user directory, browser login, account lifecycle, administrator
authorization, application registry, grants, consent policy, approved client registration,
and security audit events. Passport does not automatically consult our application assignments
or revoke tokens when an IAM administrator suspends a user. Those integration points must be
implemented before exposing the authorization/token endpoints.

HRM/CRM/ERP keep their own business authorization. Register each deployment/client type separately.
An Application domain row describes a business system; an OAuth client describes a particular
integration. Plan a one-application-to-many-clients mapping for web/mobile/environment variants,
using an explicit foreign key or mapping table in a later migration. No polymorphic relationship
or automatic creation of clients from existing application rows is needed.

Browser-only and native clients are public clients and cannot keep secrets. A backend application
can be a confidential client with server-side secret storage; it must still use PKCE under our
policy. Never put a confidential client secret into Next.js public environment variables.

## Proposed authorization-code flow

1. HRM creates a fresh verifier, S256 challenge, and random state tied to the initiating session.
2. It redirects the browser to IAM `/oauth/authorize` with response_type=code, client ID,
   exact redirect URI, requested scopes, state, and the PKCE challenge/method.
3. IAM uses its browser login session. Before approval it checks the user, client, application,
   assignment, and allowed scopes, and obtains consent according to the eventual consent policy.
4. Passport returns a code to the registered callback. HRM checks state before continuing.
5. HRM exchanges the code and verifier at `/oauth/token`, with client authentication if confidential.
6. The client presents the access token to the intended API; refresh tokens return only to IAM.

PKCE and state are required by our design, but this configuration step does not yet enforce
them on live requests. Passport's support alone must not be mistaken for complete IAM policy.
The installed League 9.4.1 AuthCodeGrant requires a challenge for public clients, supports
both S256 and plain, and does not require PKCE for confidential clients by default. Our later
request policy must require S256 for every user-facing client; merely registering a client
does not enforce that stricter policy.

OAuth is delegated authorization. This phase defines no OIDC discovery, ID tokens, `openid`
scope, UserInfo endpoint, or standardized downstream identity/login contract. Existing IAM
`/api/me` is a session endpoint, not OIDC UserInfo. Downstream sign-in semantics remain a later
design decision; merely receiving an access token must not be treated as an OIDC login.

## Configuration applied and capabilities reviewed

- Existing web/Sanctum guards and routes remain in place.
- OAuthServiceProvider disables Passport route registration unconditionally for this phase.
- Passport uses `web` for interactive user authentication and the default database connection.
- Access-token lifetime is configured to 15 minutes and refresh-token lifetime to 7 days.
  These are initial policy choices, not Passport defaults or an absolute session duration.
- No scopes or default scopes are granted yet. Define resource-specific least-privilege scopes
  together with their enforcing API endpoints before enabling issuance.
- Password and implicit grants remain disabled; device authorization is explicitly disabled.
  Passport also supports client credentials and personal access tokens; neither is provisioned
  here. The built-in server enables client credentials internally, so approved client grant types
  must restrict user-facing registrations to authorization_code and refresh_token later.
- The reviewed Passport provider uses a ten-minute authorization-code TTL. Refresh tokens are
  revoked after use by default. Full token-family reuse response requires separate review.
- Keys can be read from PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY or the package's storage paths.
  No key material is generated, committed, or distributed in this step.

No OAuth schema is published or migrated, no clients are registered, and the User model is not
yet adapted to Passport's OAuthenticatable/HasApiTokens contract. No Passport API guard is routed.
This is a configured dependency, not an operational authorization server. The installer shortcut
was deliberately avoided because it also performs schema/key setup beyond this stopping point.

## Decisions required before enabling endpoints

Implement the explicit application/client mapping and administrator-only registration. Publish
and review Passport's migrations, provision private keys securely, and add the Passport User
contract/trait plus a separate bearer-token guard for OAuth resource routes. Preserve the IAM
session routes. Integrate login continuation and consent without permitting open redirects.

Check user/application/grant eligibility at authorization, code exchange, and refresh, including
revocation between those steps. Define scope allowlists per client. Extend audits using Passport
events and policy hooks while excluding codes, verifiers, tokens, and secrets.

Decide token audience and validation before a downstream API accepts tokens: validate signature,
expiry, intended resource/client binding, scopes, and revocation/status policy. A valid signature
alone must not allow a CRM token to access HRM. Passport's default token audience is client-oriented;
do not assume it already models distinct resource audiences. Do not share IAM's private key or
database with downstream services. Any verification-key distribution or online revocation check
needs an explicit integration design; this preparation adds neither discovery nor introspection.

Define how suspension, grant revocation, client deactivation, and logout affect existing tokens.
Local JWT verification alone does not instantly observe central revocation. Current browser logout
only ends the IAM session; it does not revoke all OAuth grants. Specify refresh reuse handling and
retention/purging. Configure precise token-endpoint CORS origins if browser-based clients need it.

Reference: [Laravel Passport documentation](https://laravel.com/docs/12.x/passport),
[Passport 13.8.0 source](https://github.com/laravel/passport/tree/v13.8.0).

Stop here. Authorization UI, client registration, token issuance integration, downstream clients,
and OIDC implementation are outside this design/configuration step.
