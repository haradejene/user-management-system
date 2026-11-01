# Central IAM user management: inspection and implementation plan

Inspection date: 2026-09-19. This is a planning deliverable, not authorization to implement the proposed changes.

## Findings at a glance

The requested administrative user-management feature already has a working baseline: list, view, create, update, deactivate, suspend, reactivate, and application grant/revoke operations exist in both the Laravel API and Next.js interface. Extend and harden these paths; do not rebuild them as missing features.

The largest gaps are frontend pagination and access-state correctness, profile management, complete account lifecycle semantics, and regression coverage for security and concurrency edge cases. Deletion exists at the model/database level but has no administrative workflow. OAuth/OIDC/SSO remain future work.

No application implementation, configuration, migrations, dependencies, or HRM files were changed during this review. The only intended source-tree change is this document. Existing tests generated normal ignored build/test artifacts; browser tests used a separate temporary SQLite database. No OAuth schema or clients were created.

## Ownership boundary

| Central IAM owns | Each application, including HRM, owns |
| --- | --- |
| Central identity, credentials, authentication and central account status | Its local user representation and local account status |
| Companies and company memberships | Application business data and business authorization |
| Application registry and user application assignments | Application-specific roles and permissions |
| Future OAuth 2.0, OIDC and SSO | Enforcement of its own authorization after central authentication |
| Central audit and security controls | Application-specific audit/security controls |

For example, IAM may record **Hara: HRM assigned = yes**. HRM may independently record **Hara: local account inactive, role HR_MANAGER**. The IAM assignment neither activates the HRM account nor grants HR_MANAGER. No HRM role/permission tables, selectors, synchronization, or claims belong in this user-management implementation.

The current `application_user` and `company_user` schemas have no business-role or permission fields. `is_system_admin` is a central IAM administrative capability, not an HRM role.

## Inspection inventory

Paths below are relative to the repository root. “Implemented” means present in the inspected code, with test coverage described separately; it does not claim every edge case is production-ready.

| # | Area | Implemented and evidence | Partial or missing |
| --- | --- | --- | --- |
| 1 | User model | `backend/app/Models/User.php`: authenticatable Eloquent model, internal integer key, UUID `public_id`, UUID route binding, hashed passwords, hidden credentials, account enum, central admin flag, soft deletion, profile/company/application relationships, access predicate. | Explicit deleted-user checks are absent from domain predicates; UUID immutability is not enforced against direct model writes. No Passport user contract/trait, intentionally. |
| 2 | User migrations | `0001_01_01_000000_create_users_table.php` creates users, reset-token storage and sessions. `2026_09_01_000001_create_iam_domain_tables.php` adds status/index, admin flag, soft deletion and UUIDs; it backfills UUIDs before applying non-null/unique constraints. | No dedicated lifecycle timestamps/reasons, deletion/restore policy or populated-database upgrade regression test. Status columns are strings; enums are enforced in application code rather than database CHECK constraints. |
| 3 | User profile | `UserProfile.php`, the domain migration, `UserProfileFactory.php`, development seed data, profile Requests/service/controller/routes, allowlisted resource and profile UI provide optional nullable first/last name, phone and photo fields. Users manage their own profile; active central administrators may manage another non-deleted user’s profile. | Ordinary create/register flows do not create profiles. Photo remains an opaque string; upload/storage, phone normalization and name derivation remain undecided. |
| 4 | Companies | `Company.php`: UUID, name, active/inactive status, soft deletion. Domain migration creates its table. Company controller/service/Requests/Resource implement list/filter/view/create/update/deactivate/reactivate. | No deletion/restore workflow. Company status reuses `MembershipStatus`; harmless today but couples separate concepts. |
| 5 | Company-user relationship | `company_user`: unique company/user pair, independent membership status, timestamps, foreign keys and status indexes. `CompanyMembershipService` and controller add/list/remove membership and list a user's companies. | Removal detaches the row. No membership status transition endpoint. Membership/company state is not consulted by application eligibility, and grants have no company dimension. Decide whether that is the intended policy. |
| 6 | Application registry | `Application.php` and domain migration: UUID, name, unique slug, description, active/inactive status, soft deletion. Controller/service/Requests/Resource and frontend management exist. | Registry entries are deliberately separate from OAuth clients. No client association, redirect URI or client secret fields; no deletion/restore workflow. |
| 7 | User application access | Unique `application_user` pair, status, grantor FK and timestamps. `ApplicationAccessService` grants/revokes and paginates in both directions. `User::hasAccessToApplication()` requires an active user, active app and active pivot. Audit events are transactional. | Grant is allowed for inactive/suspended users, though it is ineffective. Revoke detaches; an existing inactive pivot cannot be re-granted without removing it first. API exposes stored status, not computed eligibility/reason. No downstream enforcement or lifecycle propagation. |
| 8 | Authentication | `AuthService`, auth controllers/Requests, `config/auth.php`, `config/sanctum.php`, `config/session.php`, CORS and `bootstrap/app.php`: Laravel web sessions with Sanctum stateful SPA authentication, CSRF, login/register/logout/me, email normalization, password hashing, session regeneration and active-account checks. Login is limited to five attempts/minute per normalized email and IP. | Public registration is enabled and creates active standard users; no registration-specific throttle. Email verification is not enforced. Reset-token storage/broker configuration exist without reset endpoints/UI. No invitation, MFA or explicit all-session revocation workflow. |
| 9 | Admin authorization | Active `is_system_admin` gate through `EnsureCentralIamAdministrator`, active-account middleware, and User/Company/Application policies. Requests and controllers authorize operations. Admin status is not mass-assignable or accepted by user Requests. Self-deactivation/suspension is rejected by `UserService`. | No production admin provisioning/recovery workflow or concurrent last-admin protection. Policy `before()` grants all abilities to admins, including delete/restore/forceDelete despite no such routes. Review before exposing destructive operations. |
| 10 | API routes | `backend/routes/api.php` registers authenticated self-profile routes plus admin profile routes alongside the existing API. All admin routes require `auth:sanctum`, active account and central admin status. Public resource identifiers are UUIDs. | No user-delete, restore, password reset, verification or audit-browsing endpoints. OAuth endpoints remain disabled. |
| 11 | Controllers | Auth: Register/Login/Logout/Me. Users: User/UserStatus. Companies: Company/CompanyStatus/CompanyMembership. Applications: Application/ApplicationStatus/ApplicationAccess. Controllers delegate mutations to services and serialize Resources. | No missing controller for the requested baseline flows. Future profile/lifecycle changes should follow this separation. |
| 12 | Services | User, Profile, Auth, Company, CompanyMembership, Application, ApplicationAccess and Audit services. Business writes and synchronous audit inserts share transactions. `IamActivityOccurred` is handled by `RecordIamActivity`. | Relationship duplicate/status checks occur before transactions and have concurrency gaps. Authentication session changes are not database transactions with audit writes; the mutation rollback guarantee should not be generalized to all authentication behavior. |
| 13 | Form Requests | Users: List/Store/Update/Profile; Auth: Register/Login; Companies: List/Store/Update/ListMemberships/AddMember; Applications: List/Store/Update/ListAccess/GrantAccess. User writes normalize email, validate uniqueness and confirmed passwords; profile fields validate nullable bounds; list sizes are bounded at 100. | Status actions use fixed controller enum values, so lack of a status Request is not itself a gap. Unknown fields are omitted through `validated()`, not explicitly rejected. Relationship `exists` rules include soft-deleted rows, followed by scoped model lookup that can return 404. |
| 14 | API Resources | User, UserProfile, Company, CompanyMember, Application, UserApplicationAccess and ApplicationUserAccess use explicit field mappings and public IDs. Passwords/remember tokens are excluded. Lists use Laravel pagination. Access resources expose assignment existence, stored assignment status, effective access and an ineffective reason. Profile resource exposes only the approved nullable profile fields. | No profile upload workflow or business/profile fields. |
| 15 | Tests | Backend feature suites cover users, profiles, authentication, authorization, companies/memberships, applications/access, schema, relationships, factories/seeding, audit and disabled OAuth routes. Frontend Vitest covers authentication, application access and profile rendering/submission/error handling. | Browser admin flow covers creation and relationships, not profile editing/status lifecycle. Security, deletion, pagination, races and multi-session behavior need targeted coverage below. |
| 16 | Next.js frontend | Next 16.3.4/React 19.2.8 App Router. Admin layout uses `ProtectedShell`; login/register use `AuthProvider`, Axios credentials/XSRF, react-hook-form and Zod. Users list/new/detail/edit, profile, company/application screens, dashboard and access manager are wired to API services. | Lists/selectors fetch only page 1 at 100 records; no status filter or paging controls. User forms show only the first validation error and auto-copy password into confirmation. |
| 17 | Passport configuration | `composer.lock` pins Passport 13.8.0 with Laravel 12.68.0 and Sanctum 4.3.3. Registered `OAuthServiceProvider` calls `Passport::ignoreRoutes()`, configures 15-minute access/7-day refresh lifetimes, empty scopes and disabled device grant. `config/passport.php` uses `web` and environment key/connection settings. | Preparation only: no published OAuth migrations, routed Passport guard, operational user contract/trait or client provisioning in project code. Existing regression test confirms disabled endpoints; no changes needed in this phase. |
| 18 | Other OAuth configuration | `docs/oauth-design.md` describes future authorization-code/PKCE integration, application-to-client separation and downstream boundaries. CORS covers API/CSRF paths. Installed Passport provider publishes migrations rather than automatically loading them. | No consent/login continuation, token issuance integration, discovery, ID tokens, UserInfo, SSO or downstream token validation. These are intentional deferrals, not this feature's work items. `/api/me` is a session endpoint. |
| 19 | Documentation | Root README describes architecture, setup and audit guarantees. `docs/oauth-design.md` records preparation and future decisions. Frontend `AGENTS.md` requires reading installed Next.js guides before coding. | Backend/frontend READMEs are mostly framework templates; root setup still says migrations will be added. No existing user-management acceptance contract, lifecycle matrix or operational admin guide was found. This document supplies the plan; later implementation should update the guides. |

## Requested user-management flows

All routes in this table have the `/api/admin` prefix. `{user}` and `{application}` identify public UUIDs.

| Intended operation | Existing API | Existing frontend | Remaining work |
| --- | --- | --- | --- |
| List users | `GET /users`, search name/email, status filter, pagination, stable name/id ordering | `/users`, debounced search, status/admin labels | Pagination, status filter, response-order protection and larger-directory tests |
| View user | `GET /users/{user}` and `GET /users/{user}/companies` | `/users/[id]`, identity/status/admin level, memberships, access-management link | Profile if approved; show company and membership states; improve independent error recovery |
| Create user | `POST /users`, normalized unique email, confirmed hashed password, active/non-admin defaults | `/users/new` | Confirm onboarding method; field-specific errors and optional approved profile fields |
| Update user | `PUT/PATCH /users/{user}`, name/email/optional password | `/users/[id]/edit` | Email-verification/session policy, validation UX, unchanged-password and immutable-field tests |
| Deactivate user | `PATCH /users/{user}/deactivate` | Detail action and confirmation | Explicit lifecycle/session guarantees and self-action UI guard |
| Reactivate user | `PATCH /users/{user}/reactivate` | Detail action for either inactive or suspended | Decide suspension-clearance rules and explain retained assignments |
| Suspend user | `PATCH /users/{user}/suspend` | Detail action while active | Differentiate security suspension from administrative inactivity |
| View/manage application access | `GET/POST /users/{user}/applications`, `DELETE /users/{user}/applications/{application}`, `GET /applications/{application}/users` | `/application-access?user=<uuid>`, grant checkbox and revoke confirmation | Complete paging/search, effective eligibility, selected-user correctness and in-flight operation safety |

There is no `DELETE /users/{user}` or restore endpoint. Deactivation does not delete a user.

## Account lifecycle and federation compatibility

### Current behavior

| State | Representation | IAM login and protected requests | Assignments and identity |
| --- | --- | --- | --- |
| Active | `status=active`, normally `deleted_at=null` | Login allowed with valid credentials; admin routes additionally require central admin flag | UUID remains stable; effective application eligibility also requires active app and active grant |
| Inactive | `status=inactive` | Login rejected. Active middleware denies protected requests and invalidates the requesting browser's session | User row, profile, memberships and grants retained |
| Suspended | `status=suspended` | Same runtime denial as inactive | Same retention as inactive; separate enum exists but no distinct reason/review policy |
| Soft-deleted | `deleted_at` set independently of status | Default Eloquent authentication queries and route binding exclude the user | Row and dependent records remain; email uniqueness still reserves the address; no API restore flow |
| Hard-deleted | Row removed with `forceDelete()` at model level | No user to authenticate | Profile and membership/access rows cascade; grantor FK becomes null where applicable. Audit subject/actor UUID history survives. No public workflow exists |

`changeStatus()` accepts any of the three enum targets through the corresponding endpoints, subject to the self-disable guard. It does not require a reason, clear memberships/grants, remove every session, rotate a lifecycle version, or publish durable downstream events. Reactivation restores central eligibility from retained active assignments. It does not restore a soft-deleted row.

The middleware rejects a non-active user when a request reaches it. A browser that makes no request during suspension may still hold its old session when the account is reactivated; the service does not proactively invalidate that session. Treat this as a lifecycle gap requiring a real multi-browser regression test, not as proof of current access while suspended. Password changes have Sanctum session password-hash checks on stateful requests, but explicit all-device/remember-me guarantees are not defined or tested.

`hasAccessToApplication()` checks user status and queries non-deleted related applications, but does not explicitly reject a soft-deleted User instance supplied through `withTrashed()` or retained in memory. Its user/application status properties can also be stale. `isCentralIamAdministrator()` similarly lacks an explicit deleted check. Current normal HTTP lookup paths exclude deleted users; future federation/background callers must not depend on that implicit protection.

### Proposed lifecycle contract to confirm

1. Keep `active`, `inactive`, and `suspended` distinct. Use inactive for administrative disabling, suspended for a security hold, and `deleted_at` for archival deletion. Deleted overrides any status.
2. Keep the existing public UUID stable across name/email changes and any authorized restoration. Never use mutable email as the federation identity key. Specify the eventual issuer/subject mapping during federation design; do not implement OIDC now.
3. Define central eligibility as: existing non-deleted active user, existing non-deleted active application, and active assignment. Add a company condition only if the company policy is explicitly approved.
4. Preserve assignments on deactivate/suspend; show them as assigned but ineffective. Decide whether reactivation can reuse them automatically or needs review after security suspension.
5. Prefer invalidating all central browser sessions and remembered authentication when disabling an account, with fresh authentication required after reactivation. Confirm this behavior before changing it.
6. Keep deletion/restore outside the initial feature unless explicitly selected. Before exposing it, decide retention, email reuse, restored status, assignment restoration and audit events. Avoid automatic active restoration with retained privileges.
7. Define downstream propagation and existing-token/session revocation later as a federation contract. The current synchronous audit event is neither a durable integration event nor delivery to HRM.

For future HRM sign-in, central eligibility is only one prerequisite. HRM must also accept the central identity, evaluate its own local account state and apply its own business permissions. IAM cannot infer HRM account activity from its grant and must never silently activate the HRM user. Central reactivation, grant/regrant and local HRM reactivation remain separate operations. This repository does not establish or verify HRM's current behavior.

## Architectural and correctness issues to address

| Priority | Finding and evidence | Proposed treatment before or during implementation |
| --- | --- | --- |
| High | `ApplicationAccessManager.tsx` previously defined granted from row existence and displayed application status only. | Implemented: assignment, effective eligibility and ineffective reason are shown separately; paginated selectors/listings and selected-user lookup prevent first-page-only behavior. No HRM role or local-account controls. |
| High | Access manager previously loaded only the first 100 users/apps/grants and accepted delayed responses without selection identity checks. | Implemented: access lists expose pagination metadata and the manager ignores stale fetch/action responses bound to an earlier selection. |
| High | Access requests have no cancellation/selection identity check. Switching user during a grant/revoke or delayed fetch can apply results to another user's displayed state; an open revoke dialog is not bound to its original user. | Bind pending actions and responses to immutable user/application pairs, block unsafe selection changes and ignore stale responses. Add delayed-response browser/component tests. |
| High | Lifecycle denial is request-time; domain helpers do not independently reject deleted/stale subjects. | Centralize a well-defined eligibility contract; test deleted objects, real sessions, remembered login and reactivation. Decide all-session invalidation first. |
| High, decision | Grants are global user/application pairs; no company association exists. Inactive company or removed membership currently has no effect on the grant predicate. | Confirm global versus company-scoped access before a schema change. Company membership must never be interpreted as an HRM role. |
| High, decision | Public `/api/register` creates active accounts and is linked from login; the UI calls it development registration but backend availability is not restricted to development. | Confirm whether production registration is public, admin-only or invitation-based. Plan throttling and onboarding accordingly. |
| Medium | UserService email changes preserve `email_verified_at`, even for previously verified addresses. Verification is not enforced today. | Clear/reverify on changed email if verification is used; test against future identity claims. Decide password-reset/invitation/session policy separately. |
| Medium | Policy `before()` returns true for admins for destructive abilities. Self-disable is guarded, but concurrent admins could disable each other; no last-active-admin invariant is locked. | Restrict destructive policies before adding routes; decide recovery and last-admin rules, with transaction/concurrency coverage. Current self-disable guard already protects a sole admin acting through that endpoint. |
| Medium | Grant/member duplicate and target-status checks precede the transaction. Unique constraints prevent duplicates but racing writes can produce unhandled database exceptions; status may change after validation. | Keep database uniqueness, provide consistent conflict/idempotency behavior, and use appropriate locking/revalidation. Verify on PostgreSQL. |
| Medium | Inactive relationship statuses exist in schema but mutation APIs only attach/detach. Regrant rejects any existing pivot, including an inactive one. | Choose attach/detach or retained status transitions explicitly; do not add another uncoordinated lifecycle. Preserve grant/revoke audit history either way. |
| Medium | Profile policy still has open phone normalization, photo storage/upload and name/display-name decisions. | Keep the implemented four nullable fields, explicit allowlist, ownership authorization and transactional audit behavior; resolve only the remaining policy decisions before expanding scope. |
| Medium | Directory searches use SQL `LIKE`; tests use SQLite, while configured primary database is PostgreSQL. | Specify case/diacritic/wildcard behavior and verify it on PostgreSQL; SQLite success does not establish equivalent case-insensitive search. |
| Medium | Frontend user detail hides all content after an action error; self-disable actions are offered; lists can accept out-of-order responses. API client has no central expired/disabled-session response handling. | Preserve usable detail on errors, disable self-actions with explanation, ignore stale responses and define 401/403/419 recovery. Review confirmation modal focus/keyboard behavior. |
| Later security work | Audit records have no browsing endpoint, retention policy or database-level immutability. Authentication audit failure behavior is not tested like transactional business writes. Development seeding resets known credentials/status and restores demo records. | Preserve current audit transaction boundaries; document safe test-only seeding and production admin bootstrap. Audit browsing/retention and authentication failure policy need separately scoped work, not an HRM authorization expansion. |

## Test coverage and gaps

Existing backend coverage includes nine UserManagement tests, eleven Authentication tests, six Authorization tests, eight ApplicationAccess tests, seven ApplicationManagement tests, ten CompanyManagement tests, fourteen AuditLogging tests, plus schema/relationship/seeder/OAuth-preparation and framework smoke tests. Passing tests validate the existing cases, not the proposed lifecycle guarantees.

Add targeted coverage alongside approved changes:

| Area | Needed cases |
| --- | --- |
| Authorization and input integrity | Guest/standard/inactive/suspended actors across every user and access endpoint; attempted writes of `is_system_admin`, status, UUID, deleted state and HRM role/permission fields with otherwise valid payloads; no privilege escalation or business-role persistence |
| Identity and profile | UUID unchanged by edit/reactivation; email normalization and uniqueness including soft-deleted users; profile absent/present/update/clear behavior; Resource allowlist; unchanged password when omitted; reject invalid/mismatched password; reset verified state on email change under approved policy |
| Lifecycle | Both self-deactivate and self-suspend; allowed transitions/no-op auditing; deletion excluded from login/list/detail/grant; helper called with a deleted instance; suspended live sessions, multiple sessions and remember-me; disable then reactivate before old browser's next request; last-admin concurrency if adopted |
| Access eligibility | Active/inactive/suspended/deleted user x active/inactive/deleted app x missing/active/inactive grant; grant-to-disabled-user policy; retained assignment after reactivation; revoke/regrant and inactive-pivot semantics; repeated and concurrent grant/revoke |
| Audit and transactions | User/profile/status/access changes roll back when audit insert fails; rejected/no-op operations produce no success event; all mutation paths record correct actor/subject; credential values excluded; authentication audit failure behavior explicitly selected |
| Database and pagination | Populated-user UUID migration, indexes/uniqueness and soft-delete behavior on isolated PostgreSQL; deterministic page boundaries; search casing; 101+ users/apps/grants/memberships and selected UUID outside first page |
| Frontend | User service/component tests for list/filter/pagination/create/edit/status validation; active-versus-assigned messaging; stale responses/user switching during pending actions; 401/403/419, 404, loading/empty/retry states; keyboard confirmation and focus restoration |
| Browser journeys | Extend existing admin flow to edit, deactivate, reject login, reactivate, suspend, reject ordinary-user access, preserve assignments and revoke/regrant; test large directories and direct user links. Keep test fixtures isolated |
| Boundary regression | Keep disabled OAuth/client/discovery tests and no-business-role schema assertions. HRM local inactivity behavior requires a later application integration contract test; do not modify HRM in this phase |

## Decisions requiring confirmation before implementation

These are open decisions, not approved changes. None prevents completing this inspection.

| Decision | Proposed starting point | Why it matters |
| --- | --- | --- |
| Production account creation | Admin-managed creation; decide whether invitations should replace administrator-entered passwords. Restrict public registration if not a product requirement | Current registration is public and immediately active |
| Inactive versus suspended | Administrative disable versus security hold; require an explicit reviewed action to clear a suspension | Current runtime behavior is identical and reactivation is unrestricted for admins |
| Existing browser sessions | Disable/suspend invalidates all central sessions and remembered login; reactivation requires fresh login | Current enforcement only clears a session that requests access while disabled |
| Deletion scope | Defer delete/restore UI/API; retain archival `deleted_at` support | Adding deletion requires retention, email reuse, restore-status and grant-restoration decisions |
| Application access scope | Keep current global assignment only if intended; explicitly decide whether company membership is required | Company-scoped grants would change schema and eligibility contracts |
| Assignment lifecycle | Confirm whether disabled users may be preassigned access and whether revocation keeps a pivot history or detaches as today | Determines UI labels, regrant behavior and audit expectations |
| Profile scope | Resolve phone normalization, name consistency and photo storage/upload policy | Current API/UI supports only the existing nullable first/last name, phone and opaque photo string; no upload or business fields |
| Central administrator recovery | Preserve self-disable protection; define last-admin invariant and controlled production bootstrap | Avoid lockout and reliance on demo seeding; no HRM role-management UI needed |
| Credential changes | Decide email verification, password delivery/reset, forced password change and session invalidation guarantees | Current admin can set passwords and edit verified email without a full onboarding lifecycle |

Future federation decisions (issuer/subject, client mapping, token/session revocation propagation and local-account linking) stay in a separate phase. Do not block frontend pagination fixes on choosing an OAuth protocol implementation.

## Implementation order after this plan is reviewed

1. **Confirm the lifecycle and access contract.** Resolve the relevant decisions above, document the transition/eligibility matrix and production onboarding expectations. Keep all OAuth/OIDC/client configuration and HRM work out of scope. Acceptance: an unambiguous distinction between central user status, assignment, application status and HRM local status.
2. **Add focused regression coverage and harden central lifecycle behavior.** Extend existing services/policies/middleware rather than introducing duplicate paths. Address deleted/stale identity checks, approved session invalidation, email-verification consistency and any selected admin invariant. Acceptance: denied users cannot regain eligibility through retained sessions or alternate service callers under the approved contract; audit remains correct.
3. **Complete user/profile API work only where approved.** Preserve existing UUID routes and Resource shapes where possible; explicitly allowlist any profile extension, validate nullable clearing and perform related writes atomically. Add new migrations only for approved new data. Acceptance: create/view/update work for users with and without profiles, and cannot modify central admin status or application business roles.
4. **Resolve application-assignment semantics and concurrency.** Preserve existing grant/revoke APIs where feasible, distinguish stored assignment from effective eligibility, settle inactive-pivot handling and provide consistent race/conflict responses. Implement company scoping only if explicitly selected. Acceptance: eligibility matrix, revoke/regrant and concurrency tests pass without HRM role/permission data.
5. **Complete the user-management frontend.** Add true pagination/status filtering, fetch selected users independently, protect requests/actions from selection races, show central status and assignment eligibility clearly, improve field errors/recovery/self-action controls, and include approved profile fields. Acceptance: every intended admin action remains usable beyond 100 records and after an API error; disabled HRM-local state is never implied to be enabled by IAM.
6. **Extend browser/database checks and operational documentation.** Run existing suites plus focused new tests and an isolated PostgreSQL run. Update setup, admin bootstrap, lifecycle, API and test-isolation documentation. Acceptance: tests/lint/build pass, no shared-database test mutations, and the documented limitations match the implementation.

Deletion/restore, invitations/MFA/reset flows, audit browsing and durable federation events are separate scope choices. Do not implement them merely because inspection found them absent. Do not enable Passport routes, create OAuth clients, change Passport configuration, implement OAuth/OIDC, or modify HRM as part of this plan.

## Checks executed during inspection

Environment: PHP 8.2.12, Node.js 24.16.0, installed project dependencies. `npm.cmd` was used because PowerShell execution policy blocks the `npm.ps1` shim; no policy change was made.

| Check | Result |
| --- | --- |
| Backend `php artisan test` | **Passed: 78 tests, 426 assertions**, using the existing in-memory SQLite test configuration |
| Backend `php vendor/bin/pint --test` | **Passed**, read-only style check |
| Backend `php artisan route:list --path=api -v` | **Passed**, 31 API routes; protected admin middleware verified |
| Frontend `npm.cmd run test` | **Passed: 2 files, 4 tests** |
| Frontend `npm.cmd run lint` | **Passed** |
| Frontend `npm.cmd run build` | Initial sandbox run failed to fetch Geist/Geist Mono from Google Fonts. **Retry with approved network access passed**, including TypeScript and page generation; no source/configuration fix was made |
| Frontend `npm.cmd run test:e2e` | **2 test bodies passed** (admin flow 31.5s; authentication flow 11.5s), but the runner remained running after both results and was interrupted (exit 1). This is **not a clean end-to-end suite exit**; investigate runner/server teardown before claiming that baseline is fully green |

Browser-test isolation: ports 3000/8000 were checked for existing listeners before starting; the existing Playwright server configuration was used with process-only `APP_ENV=testing`, `DB_CONNECTION=sqlite`, a newly created temporary database, empty `DB_URL`, database sessions, array cache/mail, sync queue and reduced test hashing cost. Only that temporary database received the existing IAM migrations and development fixtures. Configuration files and the configured development database were not changed. No OAuth migrations were present or run. The browser dev server emitted Google Fonts fallback warnings. A subsequent port check reported no listening servers on 3000/8000; the reason the parent runner did not exit was not established during this inspection.

SQLite checks do not replace PostgreSQL verification. No production database inspection, load test, dependency security audit, HRM integration test or operational federation test was performed. Existing documentation's statements about local OAuth keys/clients were checked against source/configuration and the isolated test schema, not by inspecting secrets or a deployed database.

Stop after this document and the inspection report. Implementation requires the next explicitly scoped task.
