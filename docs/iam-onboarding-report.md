# IAM onboarding and application access milestone

Implemented in the IAM only. No commit or push. Laravel SDK, HRM, OAuth authorization/token endpoints, signing, refresh-token database logic, and SDK contracts were not modified. The pre-existing untracked documents were preserved.

## 1. Existing backend capabilities reused

Application public IDs, status and policies; Passport OAuth client creation, hashed credentials and one-time plainSecret; application-scoped list/detail reads; Passport revocation of clients and their existing credentials; exact redirect matching; mandatory S256 authorization-code PKCE; OIDC issuer and discovery configuration; Passport scopes; central IAM administrator middleware; Doxa user directory/profile reads; application-access grant/detach and authoritative effective-access resources; transactional IAM AuditService/AuditLog.

## 2. Frontend audit and components

| Area | Existing implementation | Result |
| --- | --- | --- |
| Applications | ApplicationsAdmin, ApplicationsList, new/details/settings routes | Reused |
| Application details | ApplicationDetails, ApplicationTabs, ApplicationOverview, ApplicationSettings | Adds expandable administrative history |
| OAuth clients | ApplicationOAuthClients list under each application | Existing pagination, create/detail/revoke retained |
| OAuth creation | OAuthClientForm modal | Exact values, type guidance, review before create, one-time credentials and integration |
| OAuth details | OAuthClientDetail/Metadata | PKCE and scopes; redirect editor; integration details |
| Users | UsersList, UserDetails, UserForm and users.service | Existing searchable Doxa directory reused |
| Application access | ApplicationUserAccess, ApplicationUserAccessGrant, global ApplicationAccessManager | Explicit selected identity; active-user requirement; stable Doxa ID displayed; effective state retained |
| API client | Credentialed Axios api-client, applications.service, users.service | Adds redirect update; safe error mapping |
| Authorization | ProtectedShell is_system_admin check; backend central-iam-admin plus ApplicationPolicy | Reused and revoke policy made explicit |
| Audit UI | No existing application audit reader/view | ApplicationHistory and scoped backend reader added |
| Errors | AxiosError validation maps, Alert, inline FormField, retry/loading, pending and stale-request guards | Retained; focused form summaries and indexed redirect errors added; raw server messages suppressed; stable authentication interceptor prevents session-expiry closure races |

New components: OAuthClientIntegration, OAuthRedirectEditor (both in OAuthClientIntegration.tsx), ApplicationHistory. All use the existing Button, Alert and modal/layout conventions. No new top-level pages or design system were introduced.

## 3. Required API additions

- PATCH /api/admin/applications/{application}/oauth-clients/{client}/redirect-uris: exact redirect array plus current updated_at; scoped lookup; update authorization; row locks; revoked/inactive protection; 409 for stale edits. The lock order matches issuance. Second-precision monotonic timestamps avoid a lost update when edits happen within the same second.
- GET /api/admin/applications/{application}/history: centrally authorized, application-scoped, validated pagination (25 per page), deterministic order. Output allowlists action, time, actor/subject public IDs and non-secret client ID; excludes raw metadata, IP addresses, user agents and internal IDs.

Existing OAuth metadata gains issuer, discovery_url, allowed_scopes, pkce_required and pkce_method. Creation returns Cache-Control: no-store. No secret read/rotation/restore endpoint was added.

## 4. OAuth onboarding

Applications -> select application -> OAuth Clients -> Register OAuth Client -> name, type and exact redirect URIs -> review configuration -> Create client -> one-time credential warning/copy -> developer integration -> Manage application access. Public/confidential selection is explicit. Server-side Laravel applications are explained as suitable confidential clients when credentials can be stored securely. Existing clients are never converted.

## 5. Redirect URI UX

Creation uses one exact value per line, without trimming or URL normalization. Detail editing offers labeled individual URI fields, Add URI, Remove URI, Save, duplicate detection, indexed server validation and conflict recovery guidance. Backend management rejects duplicates, wildcards, fragments and surrounding whitespace. Laravel TrimStrings excludes redirect_uris.* so validation receives exact values. Authorization exact-match behavior was not changed. At least one URI remains required. Revoked clients cannot edit. Production HTTPS guidance explains the existing broader HTTP acceptance.

## 6. Integration view

Copies issuer, discovery URL, client ID, each exact redirect URI and a non-secret server-side environment example. Shows client type, grant, mandatory S256, available scopes, openid dependency and nonce guidance. The example uses the current Laravel SDK's real DOXA_ISSUER, DOXA_CLIENT_ID, DOXA_REDIRECT_URI and a placeholder DOXA_CLIENT_SECRET; discovery/grant/scopes are explanatory comments rather than invented SDK environment settings. No actual secret is interpolated. SDK config was read only. Tokens and signing keys are never rendered. Secret remains only in the existing creation component memory and is discarded when that view unmounts.

## 7. Application access and identity linking

Search the Doxa directory, explicitly select and re-fetch an existing user by stable public ID, inspect name/email/account status/Doxa ID, then grant access to the fixed application context. Inactive/suspended users are blocked in UI and service; deleted/nonexistent users are rejected. Duplicate grants retain existing backend rejection. Revoke detaches only the application assignment. Assignment existence/status and backend effective_access/ineffective_reason are separate. Effective access requires active user + active application + active assignment. No email linking, email normalization, provisioning, HRM user creation or IAM-to-business-role mapping is introduced.

## 8. Audit visibility

Application history reads stored events with refresh/retry/pagination. Existing access_granted/access_revoked events are exposed. Client creation, redirect changes and revocation now record genuine transactional AuditService events with only a non-secret client identifier (and changed field name for redirects). Historical missing client events are not fabricated or backfilled. Restore and rotation have no support/history to expose.

## 9. Exact changed files

- `backend/app/Http/Controllers/Applications/ApplicationAuditController.php`
- `backend/app/Http/Controllers/Applications/OAuthClientController.php`
- `backend/app/Http/Requests/Applications/StoreOAuthClientRequest.php`
- `backend/app/Http/Requests/Applications/UpdateOAuthRedirectsRequest.php`
- `backend/app/Http/Resources/OAuthClientResource.php`
- `backend/app/Services/ApplicationAccessService.php`
- `backend/app/Services/AuditService.php`
- `backend/app/Services/OAuthClientService.php`
- `backend/bootstrap/app.php`
- `backend/routes/api.php`
- `backend/tests/Feature/Applications/OAuthClientReadTest.php`
- `backend/tests/Feature/Applications/OAuthOnboardingTest.php`
- `docs/iam-onboarding-report.md`
- `frontend/src/components/admin/applications/ApplicationDetails.tsx`
- `frontend/src/components/admin/applications/ApplicationHistory.tsx`
- `frontend/src/components/admin/applications/ApplicationUserAccessGrant.tsx`
- `frontend/src/components/admin/applications/OAuthClientDetail.tsx`
- `frontend/src/components/admin/applications/OAuthClientForm.tsx`
- `frontend/src/components/admin/applications/OAuthClientIntegration.tsx`
- `frontend/src/components/auth/AuthProvider.tsx`
- `frontend/src/services/api-client.ts`
- `frontend/src/services/applications.service.ts`
- `frontend/src/types/oauth-client.ts`
- `frontend/tests/e2e/application-user-access.spec.ts`
- `frontend/tests/e2e/oauth-clients.spec.ts`
- `frontend/tests/unit/application-history.test.tsx`
- `frontend/tests/unit/application-user-access.test.tsx`
- `frontend/tests/unit/oauth-clients.test.tsx`
- `frontend/tests/unit/oauth-onboarding.test.tsx`

## 10. Verification

- Full SQLite IAM suite: 392 passed, 5 PostgreSQL-only concurrency cases skipped; 2719 assertions.
- Full isolated PostgreSQL IAM suite: 397 passed, 2815 assertions, including every concurrency case. TestCase isolates the iam_backend_test schema. Artisan emitted a duplicate-configuration warning, but PostgreSQL execution and all concurrency cases passed; a direct PHPUnit PostgreSQL rerun below confirmed the selected configuration explicitly.
- Final focused OAuth read/onboarding rerun after the lock/timestamp review and formatting: 16 passed, 131 assertions.
- Final direct PostgreSQL onboarding rerun: 2 passed, 28 assertions, including immediate subsequent edits and stale-edit rejection.
- Frontend: 97 unit tests in 12 files passed, including new integration, exact editing, audit reading/retry, review, inactive identity and safe error cases.
- Browser: 7 OAuth onboarding tests + 11 application access tests passed on Microsoft Edge.
- TypeScript --noEmit, ESLint, Laravel Pint --test and git diff --check passed.
- Production Next.js build passed. The first sandboxed build could not fetch existing Google Fonts; the approved network-enabled retry compiled, typechecked and generated all routes successfully.

Coverage includes existing create/view/revoke and secret absence, exact authorization redirects, PKCE and security suites; new redirect management authorization/cross-application scoping/duplicates/fragments/wildcards/whitespace/conflicts; user lookup/grant/revoke/nonexistent/deleted/inactive/duplicate/unauthorized access; no token/extra-secret rendering; audit authorization and stored event display.

Raw full-suite and browser logs are in ../artifacts/iam-onboarding/. The final focused backend and successful build outputs were also reported in the session.

## 11. Backend gaps

No existing secret rotation or client restore management support; no client expiration field; scopes are global IAM capabilities, with no per-client scope configuration. Access revocation detaches assignment records, so revoked users disappear from the current assignment list and are represented in history. Client audit events from before this change may be absent. Existing HTTP redirect validation is not restricted to localhost and general URL validation does not impose a production HTTPS-only policy. These gaps were not solved by inventing SDK/token/lifecycle behavior.

## 12. Security concerns and boundaries

Production deployments must register HTTPS callbacks; current management validation continues to accept HTTP beyond localhost. Stronger environment-specific redirect scheme/host policy is a separate backend product decision. The UI explicitly explains HTTP as local-development use. All management remains central IAM admin-only; application IDs/client IDs are checked server-side. Public clients retain no secret and all authorization-code clients retain mandatory S256. No stored secret can be recovered through detail/list/history or environment examples. Raw exception messages are not rendered; validation messages come from controlled backend rules. Access semantics are unchanged except the expressly requested rejection of new grants to inactive/suspended accounts. Historical audit gaps are disclosed. No SDK, HRM, refresh, signing or OAuth protocol security was rewritten.

## 13. Complete diff/stat

See ../artifacts/iam-onboarding/complete.diff and diff-stat.txt. These include every modified tracked implementation/test file, all new implementation/tests and this report, without staging anything. They exclude the generated diff/log artifacts themselves and the four pre-existing untracked user documents. Ordinary git diff omits untracked files, so the exported patch also includes each new file explicitly.
