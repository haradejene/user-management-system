# Host account-resolution contract

Apps can call `DoxaClient::resolveAccount(Request, Contracts\AccountResolver)`.
It completes the existing OIDC callback and a fresh client-bound application-access
check before invoking `AccountResolver::resolve(DoxaIdentity): ?Authenticatable`.
The SDK never creates local users, assigns roles or logs in local sessions.

The additive `handleAuthorizedCallback` returns a validated identity only after
the same fresh check. It sends the transient access token only to the fixed
issuer-relative `/oauth/application-access`, accepts only 200 JSON with exactly
`{"effective":true}`, and fails closed. Credentials never reach the host resolver
or result. Callback state, PKCE, signature, issuer, audience, nonce and expiry
validation, single-use transactions, secure transport and managed-link behavior
are retained. The existing identity-only `handleCallback` API remains compatible.

Implement the interface in each application. Reuse an exact trusted `(iss, sub)`
mapping first. Explicitly choose deny-unknown or provision-unknown policy. Any
new local user and unique mapping must commit in one host database transaction;
handle competing inserts by rollback and re-reading the winning mapping. Do not
create a second user for a disabled/deleted/provisional mapping.

Provisioning is opt-in host policy: require an explicit email-verification policy,
deny email collisions rather than auto-linking, and choose a safe local default
role. Central app access never grants an application administrator role. Preserve
existing roles, permissions and local lifecycle. Keep business-specific company,
employee and tenant creation outside the SDK. After resolution, the application
alone establishes and rotates an eligible local session.

CRM and future apps implement their own resolver, schema, creation adapter, roles
and activation requirements. An existing-user association must use a verified,
explicit, auditable linking/migration workflow, never an email fallback. Future
logins recheck application access and reuse the same identity mapping. Existing
sessions do not gain continuous revocation enforcement from this callback API.
