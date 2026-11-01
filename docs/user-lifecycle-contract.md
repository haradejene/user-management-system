# Central IAM user lifecycle contract

Date: 2026-09-19. Status: proposed contract for review and subsequent implementation.

Implementation note (2026-09-23): the reviewed central account hardening phase is now implemented. The specific current-behavior updates and verification boundaries below supersede the original inspection statements for that phase; other product decisions and deferred workflows remain unchanged.

Sources: [user-management inspection and plan](user-management-implementation-plan.md), [OAuth preparation design](oauth-design.md), and the existing IAM implementation referenced below.

**Current** describes inspected behavior. **Required** describes the target behavior for future implementation, not a claim that it is implemented. **Decision pending** identifies a product or operational choice that must be resolved before changing that area. Drafting this contract does not authorize implementation.

This contract adds no account states, database changes, routes or protocol functionality. OAuth/OIDC, Passport route enablement, OAuth clients and HRM changes remain out of scope.

## 1. User account states and transitions

The only account status values are `active`, `inactive` and `suspended`. **Soft-deleted** is an archival condition represented by `deleted_at`, not a fourth value in the status enum. Wherever this document says a user or application is **deleted**, it means soft-deleted unless expressly discussing physical removal. Deletion overrides an underlying `active` status.

| State | Meaning | Central login | Existing central sessions: required behavior | Related records |
| --- | --- | --- | --- | --- |
| Active | Central identity is enabled | Eligible with valid credentials and applicable authentication controls | Usable only if independently valid; activation does not revive invalidated sessions | Retain assignments, company memberships and profile |
| Inactive | Administrative deactivation, such as an intentional account disable | Denied | Invalidate all existing sessions and remembered authentication | Retain all related records and their existing statuses |
| Suspended | Security hold requiring explicit review before release | Denied | Invalidate all existing sessions and remembered authentication | Retain all related records and their existing statuses |
| Soft-deleted | Archived identity excluded from ordinary identity operations | Denied regardless of underlying status | Invalidate all existing sessions and remembered authentication | Retain related records for archival purposes; hide from ordinary user queries |

Administrative deactivation is reversible disabling, suspension is a security decision, and soft deletion is archival removal. None means “remove this person's HRM role.” None automatically deletes or changes a profile, company membership or assignment.

### Required transition rules

Every permitted mutation requires a currently authenticated, active, non-deleted central IAM administrator and the safety rules in section 6. Status transitions use explicit lifecycle operations; ordinary profile/user updates must not change status or `deleted_at`.

| From | To | Contract |
| --- | --- | --- |
| Active | Inactive | Allow explicit administrative deactivation |
| Active | Suspended | Allow explicit security suspension |
| Inactive | Active | Allow explicit reactivation; require fresh login afterward |
| Inactive | Suspended | Allow escalation to a security hold |
| Suspended | Active | Allow only explicit release of suspension after review, recorded as such; require fresh login afterward |
| Suspended | Inactive | Reject an ordinary deactivate action: it must not downgrade/bypass the security hold. A future hold-release-to-inactive workflow needs a separate approved contract |
| Active / inactive / suspended | Soft-deleted | Archival operation only, if a deletion workflow is later approved; not a status toggle |
| Soft-deleted | Active | Reject direct reactivation; restoration is a separate operation |
| Soft-deleted | Inactive | Proposed restoration target if restoration is approved; a separately authorized reactivation is still required |
| Soft-deleted | Suspended | Proposed restoration target for an identity archived while suspended, so restoration cannot clear its hold |
| Any state | Same state | Authorized no-op: no duplicate transition audit event and no new assignment/session. It must still enforce current denial and admin safety rules |

Deletion and restoration endpoints remain deferred. If enabled later, restoration preserves the UUID and related records, never authenticates the user, and must preserve any pre-deletion security hold. Retention duration, purge authority and restoration review are pending decisions. Physical removal is outside this lifecycle workflow; the current foreign-key cascades on physical deletion must not be mistaken for soft-deletion behavior.

Reactivation changes central status only. Active retained assignments can become effective again when all eligibility conditions hold. Inactive assignments stay inactive; absent/revoked assignments stay absent. Company memberships retain their previous statuses and are not recreated or reactivated. Profile values are preserved. Clearing a suspension requires review of retained assignments before release; it does not automatically rewrite them.

**Current:** `UserService::changeStatus()` locks and reloads the actor and target, rejects deleted identities and suspended-to-inactive, preserves related data, rejects self-disable, and audits only actual transitions. Explicit reactivation of a suspended user records `reason=suspension_released` on `user.status_changed`. This distinguishes release from ordinary reactivation; it does not assert that software collected review evidence. Review remains an administrator responsibility, with no review UI or approval workflow. Eloquent supports soft deletion/restoration, but no administrative delete/restore routes exist; restoration rules remain deferred.

## 2. Application access

IAM answers: **“Can this central identity currently access this registered application?”** An application answers: **“What can this user do inside the application?”** Central effective access is an eligibility decision; it does not establish a live session or prove downstream account readiness.

| Term | Exact meaning in the current global model |
| --- | --- |
| Assigned | A persisted user/application relationship exists, irrespective of its status. An inactive relationship is still assigned |
| Active assignment | The relationship exists and its own `status` is `active`. This alone does not imply effective access |
| Inactive assignment | The relationship exists with `status=inactive`; assigned is yes, effective access is no |
| Effective access | The user exists, is not deleted and is active; the application exists, is not deleted and is active; and the assignment exists and is active |
| Inactive user | Central `status=inactive`; no central login or effective application access, even with an active assignment |
| Inactive application | Registered application has `status=inactive`; no effective access to that application. The user's central login is unaffected |
| Revoked access | An explicit revoke has withdrawn the assignment. Under the existing detach model the relationship is absent; assigned and effective are both no. Historical audit records remain |

For the global baseline, the complete predicate is:

```text
effective(user, application) =
    user exists AND user.deleted_at is null AND user.status = active
    AND application exists AND application.deleted_at is null
    AND application.status = active
    AND assignment exists AND assignment.status = active
```

No central-admin bypass, company condition, email match, profile field, HRM role or HRM permission is implicit in this predicate. It must use authoritative current persisted state, not stale object properties or cached allow decisions after a lifecycle change. Missing or deleted entities deny access. A valid authenticated session is additionally required to exercise access; the predicate does not log the user in.

Example of independent records, not a coupling rule:

| System | Example |
| --- | --- |
| IAM | Hara -> HRM -> assigned = yes; effective = yes |
| HRM | Hara -> local account = active; role = HR_MANAGER |

IAM must not store, select, grant or synchronize `HR_MANAGER` or any HRM-specific permission. IAM cannot assert HRM local account activity from its own assignment. Grant, regrant and central reactivation must not silently activate an HRM local account. No local HRM state or role is a dimension of the eligibility matrix below.

### Assignment mutation and display contract

- Preserve existing active/inactive relationships on user deactivation, suspension or soft deletion, and on application deactivation or soft deletion. Retention never means eligibility. An explicit revoke is the exception: remove the relationship under the current detach model.
- Revocation must make access ineffective at the next central eligibility evaluation, retain audit history, and not log the user out of IAM or affect assignments for other applications. Repeated revoke is a successful no-op with no duplicate success audit event.
- User or application reactivation must not recreate revoked assignments. A new explicit grant is required. An inactive assignment must never be displayed as active merely because its row exists.
- Current creation of a grant requires an active non-deleted application and a non-deleted user. The current service permits preassignment to inactive/suspended users; those assignments remain ineffective. Whether to keep permitting that administrative operation is pending; it does not change eligibility.
- Retain current detach semantics until a separate decision explicitly replaces them. There is no assignment-status editing endpoint today. Regrant of an existing inactive row currently fails as a duplicate; choosing an explicit activate-assignment operation or revoke-then-grant remains pending. Never silently promote an inactive row.
- Any successful mutation must apply to the exact selected public user/application UUID pair. A pending action or delayed response must not be rebound to a different selected user. Unique assignments and coherent audit outcomes must hold under concurrent operations.
- Frontend must distinguish assigned, assignment status, central effective access and blocking conditions. It must not infer “unassigned” from a partial first page or “effective” from a checked box. HRM local state is unknown to this interface.

**Current:** `User::applicationAccessState()` is the central persisted eligibility predicate used by `hasAccessToApplication()` and access-list decoration. It distinguishes assignment existence, assignment status, effective access and the blocking reason, and rejects deleted/stale users and applications. Grants and revokes lock the relevant rows inside their transactions; the database unique key remains the final duplicate-assignment protection. Access resources expose assignment/effective state and reason, and the access manager uses paginated user, application and assignment lists with request/action identity guards. IAM still exposes no application-local role or permission.

## 3. Company scope

**Current behavior is A: global user/application access.** The unique relationship is `(user_id, application_id)`; it has no `company_id`. Company memberships are separate `(company_id, user_id)` records. `hasAccessToApplication()` does not inspect them.

Under this baseline, removing/inactivating a membership or inactivating/deleting a company does not by itself revoke a global application assignment, prevent central login, or change effective application access. A user with no company membership can have effective global access. A membership never grants an application assignment or business role by itself.

**Decision pending:** retain A for the intended product, or adopt **B: company-scoped access**. Choosing B requires decisions about selected company context, company/application availability, membership eligibility, grant uniqueness and migration of existing global grants. “Member of any active company” must not be invented as a substitute for that decision. No schema or implicit eligibility change is authorized here.

The matrix and global-scope acceptance cases in this document explicitly use A. If B is selected, revise this contract and add company dimensions before implementing company-dependent eligibility. Central login must remain conceptually separate from application/company authorization.

## 4. Account creation and onboarding

| Area | Current behavior | Contract before changing onboarding |
| --- | --- | --- |
| Public registration | Public `POST /api/register`; UI links to register. Creates active, non-admin user and authenticates a new browser session. Not restricted to development by backend routing | Decide whether production registration stays public or becomes admin/invitation-only. Until selected, document the current behavior without claiming a restriction. If disabled later, enforce at the API as well as the UI |
| Administrator creation | Authorized admin `POST /api/admin/users` creates active, non-admin user. Does not log in as the new user or replace the administrator's session | Preserve separation of administrator actor and created identity. Creation alone must not assign companies/apps or grant central administrator status |
| Initial password | Both paths require a confirmed password, stored hashed; registration uses minimum 8, admin Requests use framework password defaults. No forced change or invitation exists | Never use a shared/default password for real accounts, return plaintext credentials in Resources, or log them. Decide secure delivery/user selection, expiry and first-login change before replacing the current flow |
| Email verification | New users are ordinarily unverified. Login does not enforce verification. Admin update normalizes email and clears verification only when the normalized address changes | Verification remains independent metadata. No login gate or verification UI is introduced |
| Future invitation | Absent | A separately approved flow must define who may invite, proof of acceptance, single use/expiry, credential selection, duplicate identity handling and audit. It must obey the same UUID/admin/assignment boundaries. No invitation or pending account state is introduced here |

Required for all creation paths: normalize email consistently with the existing API, enforce uniqueness, generate a new stable UUID, hash credentials, exclude secrets from responses/audits, and prevent user-supplied admin/status/deletion/identity fields from escalating privilege. Current uniqueness reserves soft-deleted users' email addresses; changing reuse policy is pending. An invitation proposal must explicitly decide when the user row is created or use an existing disabled state; it cannot smuggle in a new lifecycle state.

No email-verification gate is added to the baseline matrix. If one is approved later, revise the login/eligibility prerequisites and add verification test dimensions first. Profile completeness also is not an authentication prerequisite.

## 5. Session behavior

Required target for the central IAM browser/session system:

1. A successful deactivation or suspension invalidates **all** prior central sessions and remembered authentication for that user, across browsers/devices, including clients that are offline during the transition. Soft deletion has the same requirement.
2. Every protected request authorized after the committed transition must evaluate current account eligibility and reject the disabled/deleted user. This guarantee does not claim to undo already-completed requests or downstream business actions. A concurrent login must not leave a usable session that survives the disabling transition.
3. Reactivation creates no session and restores no old session or remember-me credential. A browser inactive throughout deactivate/suspend then reactivate must still perform fresh interactive authentication. A new login after reactivation may create a new session and new remember-me credential.
4. Central assignments can become eligible again on reactivation, but cannot be exercised with invalidated authentication. Restoring assignments and restoring authentication are different things.
5. The frontend clears its authenticated state when the API rejects an invalid/disabled session and routes the user to login with an appropriate message. It must not continue showing usable admin controls solely from stale client state. Backend denial remains authoritative.
6. Ordinary logout currently ends the calling browser session. This contract does not redefine logout as all-device logout. Application grant revocation/application inactivity likewise does not invalidate the identity's central login session.

The mechanism for invalidating all sessions/remembered credentials is an implementation choice, but the guarantees above are mandatory target outcomes. They apply irrespective of the eventual session storage mechanism. Existing OAuth tokens or HRM sessions are not covered by this browser-session guarantee; future federation must define its own revocation and propagation contract.

**Current:** `users.session_version` is incremented and `remember_token` rotated on each actual transition to inactive or suspended, within the status/audit transaction. The synchronous Laravel `Login` listener stamps the validated user's version for password login, registration and remember-cookie authentication. Protected account middleware compares the session stamp with current persisted status/version. Reactivation does not reset the version. Old sessions remain invalid even if offline through disable/reactivate; deleting session-store rows is unnecessary and would not alone prevent an in-flight request from rewriting an old session. Credential login locks the user row through validation and remember-token writes, so it cannot overwrite a committed disabling rotation. A previously validated remember-cookie snapshot retains its old version if disabling races with it.

Deployment requires the session-version migration before the code serves requests. Existing sessions without a version must authenticate again; valid remember cookies can still establish authentication unless revoked by a lifecycle transition. This mechanism is independent of session storage. Soft-deleted users are denied by the provider, middleware and domain predicates. No delete/restore workflow is implemented: any future restoration must atomically advance the version and revoke remembered credentials before restoration, so pre-deletion sessions cannot return. Raw Eloquent delete/restore or direct SQL is not an approved lifecycle API.

## 6. Administrator safety

Required managing actor: a persisted, non-deleted user with `status=active`, `is_system_admin=true`, valid current authentication and authorization for the operation. Guest, standard, inactive, suspended and deleted actors must not manage users or application assignments. Owning HRM access or any HRM-local role conveys no IAM administrator authority.

- Self-deactivation and self-suspension are rejected, with no state mutation or transition-success audit. Frontend must disable or omit these actions with an explanation; backend rejection is mandatory. This rule applies even if other administrators exist.
- Once an operational administrator exists, ordinary management must not reduce the number of active, non-deleted central administrators below one. This includes future delete/demote operations if exposed. Concurrent changes must preserve the invariant; checking a count before an unlocked mutation is insufficient. The count and actor eligibility must be validated consistently with the mutation.
- The last-admin rule complements, not replaces, self-disable protection. It does not authorize a compromised user to bypass security policy; exceptional incident recovery belongs to a controlled operational procedure.
- Bootstrap must be an explicit operator-controlled process for the initial administrator. Recovery when no administrator can authenticate must verify operator authority, preserve existing identity/audit history, use non-default credentials and invalidate credentials/sessions as appropriate. It must not expose a public “make me admin” path.
- Normal registration/user edits cannot assign `is_system_admin`. Production must not depend on rerunning `DevelopmentSeeder`, which resets demo credentials/status and can restore demo records.
- Central lifecycle/access mutations must record the administrator, target UUID and actual transition. No-op/rejected actions must not create transition-success events. Preserve transactional business/audit guarantees. Suspension release must be distinguishable from routine reactivation; review evidence format and operational approver procedure are pending.

**Current:** middleware/policies enforce active, non-deleted central admin status. Lifecycle mutations lock actor and target rows in primary-key order within the mutation/audit transaction, recheck persisted actor eligibility and session version, and reject self-disable. Every successful disabling operation therefore retains a distinct active administrator locked through commit. This minimum invariant covers the existing lifecycle service operations; PostgreSQL concurrency verification remains separate from deterministic SQLite tests (see [verification boundaries](lifecycle-hardening-verification.md)). No production recovery/bootstrap workflow is added. Policy `before()` broadly allows admin abilities including destructive ones; future delete/demote workflows must extend the invariant before being exposed. Deleted actors are also rejected by direct lifecycle service calls.

## 7. Proposed central profile

| Field | Central purpose | Proposed basic contract |
| --- | --- | --- |
| `first_name` | Identity display name component | Optional nullable string, at most 100 characters |
| `last_name` | Identity display name component | Optional nullable string, at most 100 characters |
| `phone` | Central contact detail | Optional nullable string, at most 30 characters; no implication of verified ownership, MFA or an HR record |
| `profile_photo` | Central identity avatar reference | Optional nullable string, at most 2048 characters; no upload/storage behavior implied |

These bounds match the existing schema. Profile existence and completeness must not affect login, assignment eligibility, memberships or central admin status. One optional profile belongs to one central identity. Deactivation, suspension, reactivation and archival preserve it. Future writes must allow explicit null to clear optional fields, keep omitted fields unchanged, validate allowed fields, and serialize an explicit allowlist excluding internal IDs and credentials.

Business data such as employment status, job title as an authorization input, payroll, manager relationships, departments, employee records, HRM roles and permissions remain application-owned. A central phone/photo change must not trigger an HRM business update.

Profile ownership for the current implementation is explicit: an authenticated active user may view and edit their own profile, and an active central IAM administrator may view and edit another non-deleted user’s profile. Profile writes never modify `users.name`, email, password, lifecycle, administrator status, memberships or assignments. Phone normalization, photo storage/upload policy and any relationship between `users.name` and profile names remain separate product decisions; `photo` is currently an opaque nullable string.

**Current:** model, table, factories and demo profile data exist. Profile GET/PATCH endpoints, an allowlisted resource, transactional service/audit behavior, and user/admin profile UI now exist. Profiles remain optional; ordinary registration/admin creation still do not populate them.

## 8. Identity stability

Required: the public UUID identifies the same central identity throughout its lifecycle. Email, password, name/profile changes, company membership changes, grants, deactivation, suspension, reactivation and any authorized restoration must not change it. Clients cannot choose or replace it through user/profile requests. Never reassign a former identity's UUID to a different person.

Email is a normalized, unique but mutable contact/login attribute. Email must never become the federation identity, a substitute subject, or a basis for automatically linking a different account. Changing or eventually reusing an email must not transfer assignments or identity history.

Future federation must identify a person by the pair **issuer + subject**. Plan to anchor the subject to the stable central identity/public UUID; the exact subject representation and issuer value remain future federation design decisions. The pair, not email alone or a local database sequence alone, is the external identity key. No OIDC claim, token or endpoint is implemented here; `/api/me` remains a session identity endpoint.

**Current:** UUID route binding and generated public IDs exist, and normal API edits do not accept UUID changes. Model-level immutability and a federation subject contract are not implemented. Future subject tests must prove that email/profile edits cannot change the identity pair.

## 9. Central eligibility matrix

This is the exhaustive **4 x 3 x 3 = 36** matrix for current scope A, using the target deleted-state safeguards. “Login yes” means the account state permits a fresh central login with valid credentials and ordinary controls; it does not bypass password checks, rate limits or admin authorization. Application state and assignment never decide central login. An invalidated session is not a fresh login.

“Retain yes” means preserve an existing relationship and its status through the listed lifecycle conditions, including archival. It does not mean create a missing relationship or keep a row after an explicit revoke. “No row” means leave absent. Deleted entities here are soft-deleted; physical purge/cascades and retention expiry are outside this table. Company state, email verification and HRM local behavior are not matrix dimensions.

| User | Application | Assignment | Can central IAM login? | Effective application access? | Should assignment be retained? |
| --- | --- | --- | --- | --- | --- |
| Active | Active | Absent | Yes | No | No row |
| Active | Active | Active | Yes | Yes | Yes |
| Active | Active | Inactive | Yes | No | Yes |
| Active | Inactive | Absent | Yes | No | No row |
| Active | Inactive | Active | Yes | No | Yes |
| Active | Inactive | Inactive | Yes | No | Yes |
| Active | Deleted | Absent | Yes | No | No row |
| Active | Deleted | Active | Yes | No | Yes |
| Active | Deleted | Inactive | Yes | No | Yes |
| Inactive | Active | Absent | No | No | No row |
| Inactive | Active | Active | No | No | Yes |
| Inactive | Active | Inactive | No | No | Yes |
| Inactive | Inactive | Absent | No | No | No row |
| Inactive | Inactive | Active | No | No | Yes |
| Inactive | Inactive | Inactive | No | No | Yes |
| Inactive | Deleted | Absent | No | No | No row |
| Inactive | Deleted | Active | No | No | Yes |
| Inactive | Deleted | Inactive | No | No | Yes |
| Suspended | Active | Absent | No | No | No row |
| Suspended | Active | Active | No | No | Yes |
| Suspended | Active | Inactive | No | No | Yes |
| Suspended | Inactive | Absent | No | No | No row |
| Suspended | Inactive | Active | No | No | Yes |
| Suspended | Inactive | Inactive | No | No | Yes |
| Suspended | Deleted | Absent | No | No | No row |
| Suspended | Deleted | Active | No | No | Yes |
| Suspended | Deleted | Inactive | No | No | Yes |
| Deleted | Active | Absent | No | No | No row |
| Deleted | Active | Active | No | No | Yes |
| Deleted | Active | Inactive | No | No | Yes |
| Deleted | Inactive | Absent | No | No | No row |
| Deleted | Inactive | Active | No | No | Yes |
| Deleted | Inactive | Inactive | No | No | Yes |
| Deleted | Deleted | Absent | No | No | No row |
| Deleted | Deleted | Active | No | No | Yes |
| Deleted | Deleted | Inactive | No | No | Yes |

An entirely missing user cannot log in and has no effective access. An entirely missing application cannot receive effective access but does not affect an existing user's central login. Evaluation must not create a relationship in either case.

## 10. Open decisions and implementation discrepancies

### Decisions still requiring confirmation

| ID | Decision | Baseline used here |
| --- | --- | --- |
| D1 | Global or company-scoped access | A is current; no company condition until B is explicitly selected and specified |
| D2 | Production public registration versus admin/invitation-only onboarding; initial password delivery and verification gate | Current registration/admin flows remain documented, not changed; invitation absent |
| D3 | Expose deletion/restoration at all, retention/purge/email reuse and review procedure | Deferred; proposed restore to inactive, or suspended for a retained hold; no direct restore-to-active |
| D4 | Preassignment for disabled users; activate-inactive-assignment workflow; detach versus status-based revocation | Current preassignment and detach behavior described; ineffective while disabled, no implicit row activation |
| D5 | Suspension-release review evidence and authorized operational procedure; bootstrap/recovery procedure | Explicit reviewed release by central administration; concurrent last-admin safety and controlled operator recovery required |
| D6 | Remaining profile policy: phone normalization, photo storage/upload and name/display-name relationship | Authority and four nullable field bounds are implemented; no upload workflow or business fields |
| D7 | Exact future issuer/subject representation and downstream revocation protocol | Stable issuer + subject identity, never email; protocol work deferred |

The proposed transition restrictions, all-session invalidation, restoration defaults and last-admin invariant require review as target policy before implementation. D1-D7 must not be silently resolved by a coding change. They do not weaken the unconditional IAM/application ownership boundary.

### Current behavior that does not meet the target

| Current behavior | Future task |
| --- | --- |
| Status changes now revoke sessions/remember credentials transactionally | PostgreSQL concurrency and separate-browser checks remain to be executed as described below |
| Suspended-to-inactive rejected; suspension release has a distinct audit reason | Review evidence/workflow remains D5, outside this phase |
| Deleted/stale models now denied by authoritative domain eligibility | Preserve this boundary in future federation and assignment work |
| Self-disable rejected; actor/target rows locked and current actor reauthorized | Future delete/demote/restore/operator operations must join an approved invariant strategy |
| Changed normalized email now clears verification; identical normalized email preserves it | No verification gate or UI added |
| Profile API/UI and allowlisted Resource now implemented | Preserve optionality and lifecycle independence; keep remaining D6 policy decisions separate |
| Access UI conflates row existence with access, omits later pages and can mix selected-user responses | Display assignment/effectiveness separately and bind actions/results to UUID pairs |
| Grant duplicate/status checks can race | Ensure consistent current-state validation, uniqueness and audit under concurrency |
| Restoration and production recovery lack workflows | Keep deferred until decisions are approved; do not expose permissive model operations as policy |

Public registration, absent invitations and global company-independent grants are **open product decisions**, not automatically bugs. UUID binding, active login denial, data retention on status changes and no-business-role schemas already provide part of the contract.

### Verification for this document

Read-only review covered the two source documents, account/profile models and enum, User/Auth/ApplicationAccess/CompanyMembership services, user/auth Requests, account middleware, user policy, API routes, domain migration, access UI and OAuth provider. Existing source still supports the inspection's behavioral conclusions. No database was queried or modified and no application/test server was started.

Application suites were not rerun: this is a documentation-only change and no runtime assumption required a new execution. Previous suite results remain in the inspection plan, including the interrupted Playwright teardown caveat; they are not new results for this task. Document checks verify all 36 matrix combinations and the intended file-only change.

## 11. Testable acceptance criteria

These describe target assertions, not tests added or passed in this task. **Conditional** cases await the stated decision/workflow. All other cases are requirements for the proposed contract once approved. Backend tests must include direct domain calls as well as HTTP paths where relevant; frontend controls never substitute for backend enforcement.

| ID | Given / when | Expected result and suggested test layer |
| --- | --- | --- |
| AC01 | Correct credentials for an inactive, suspended or soft-deleted user | Login fails, no usable session is established, no successful-login audit is recorded. Parameterized PHPUnit cases |
| AC02 | Every user/application/assignment combination in section 9 | Exactly the stated login eligibility, effective-access boolean and row retention; deleted fixtures retain underlying active status to prove deletion overrides it. PHPUnit data provider |
| AC03 | Existing sessions A/B and remember-me credential R; administrator deactivates or suspends the user | A/B/R fail all subsequent protected authentication attempts, including another device. Status/profile/memberships/assignments have the specified outcomes. Backend session and browser tests |
| AC04 | Browser A stays offline through disable then reactivate | A's old session and R remain unusable; fresh valid login succeeds and creates new authentication. Backend/browser test for both inactive and suspended paths |
| AC05 | Login races with committed disabling, or an already-loaded User/Application object predates a status/deletion change | No surviving usable login or stale effective-access result at an evaluation after the transition. Backend concurrency/domain tests |
| AC06 | Active user with active assignment becomes inactive/suspended, then is explicitly reactivated | Assignment row/status, profile and membership data unchanged; access false while disabled and true afterward only for an active non-deleted app, with fresh login required to exercise it. PHPUnit/browser |
| AC07 | Inactive or absent assignment followed by user/application reactivation | Assignment remains inactive/absent; effective remains false. PHPUnit |
| AC08 | Active user; application inactive or deleted | Central fresh login remains eligible; application access false; existing grant retained. Fresh grant to inactive/deleted app is rejected. PHPUnit |
| AC09 | Explicit revoke followed by revoke again, then user/application reactivation | Row absent, no effective access, one revocation event for the actual removal, no implicit regrant; unrelated grants and central login unaffected. PHPUnit/browser |
| AC10 | Valid grant/revoke payload includes `role=HR_MANAGER` or HRM permission fields | No business role/permission is stored, returned as granted, or sent downstream; the central assignment operation cannot grant those capabilities. PHPUnit with otherwise valid identity IDs |
| AC11 | Central reactivation or HRM assignment/regrant | Only central IAM lifecycle/assignment effects occur; no HRM local activation or role mutation is invoked. PHPUnit interaction assertions; future integration test may use an inert downstream fake without modifying HRM |
| AC12 | Guest/standard/inactive/suspended/deleted managing actor attempts each admin operation | Access denied, target unchanged, no transition-success audit. Include a deleted actor passed directly to domain authorization. PHPUnit |
| AC13 | Admin requests own deactivate or suspend, with one or several admins present | Reject both operations, keep actor active, create no transition-success event; browser omits/disables self-actions with explanation. PHPUnit/browser |
| AC14 | Two active admins attempt to disable each other concurrently | Committed outcome retains at least one active non-deleted admin; ineligible actor cannot complete a later mutation. PHPUnit against an isolated database supporting the intended locking |
| AC15 | Suspended target receives ordinary deactivate, or unreviewed suspension release | Target remains suspended and no transition-success audit is emitted; reviewed explicit release can reactivate while preserving retained data. PHPUnit/browser after review mechanism is specified |
| AC16 | Repeated authorized status operation, rejected transition, or audit persistence failure | No duplicate success for no-op/rejection; business mutation rolls back on audit failure; successful changes identify actor, UUID and transition without secrets. PHPUnit |
| AC17 | Name, email, password, profile or membership changes and disable/reactivate operations | Public UUID remains exactly unchanged; attempts to replace UUID/admin/status/deletion fields through ordinary edit cannot change those values. PHPUnit |
| AC18 | Verified user's normalized email changes, or identical normalized email is resubmitted | Changed address clears verification; identical normalized address preserves it. No UUID/grant transfer. PHPUnit |
| AC19 | Administrator creates a user with valid input | New user active and non-admin, hashed confirmed password, no implied assignments/memberships, administrator session unchanged, secrets absent from response/audit. PHPUnit/browser |
| AC20 | Public registration under current enabled policy | New active non-admin identity authenticates without a verification gate; cannot set admin privileges or application assignments. Conditional replacement: if D2 disables registration, API and UI both enforce that policy. PHPUnit/browser |
| AC21 | Profile absent or fields null, then approved fields edited/cleared | No effect on login/access; omitted fields unchanged; explicit null clears; field bounds enforced; only allowed fields serialized; UUID stable. PHPUnit/frontend coverage |
| AC22 | User with no memberships has active global grant; membership removed or company disabled | Effective access and central login unchanged if all global predicate terms still hold. PHPUnit for scope A; replace/add company dimensions if D1 selects B |
| AC23 | Inactive assignment, disabled user or disabled application rendered | UI shows assigned state independently and effective=no with correct central blocking condition; never implies HRM local readiness. Frontend/browser |
| AC24 | Selected UUID beyond first 100 entries, or delayed fetch/grant/revoke while switching selection | Complete correct user's state displayed; no false absent grant, no cross-user action/result, no status inferred from incomplete pages. Frontend/browser |
| AC25 | Archived user receives normal view/login/reactivate request | Ordinary lookup/login denied and no restoration occurs. Conditional approved restore preserves UUID/data, invalidates sessions and restores to inactive or retained suspended hold, never directly active. PHPUnit |
| AC26 | Future federation subject mapping evaluated before/after email/profile change | Issuer + subject unchanged, email only an attribute; same email at another issuer or on another identity never causes automatic linking. Deferred identity-mapping unit tests, not OIDC implementation |
| AC27 | Future invitation accepted/expired/reused | Conditional D2 flow preserves non-admin defaults and UUID uniqueness, denies expired/reused acceptance, and grants no business role; no additional account state. Deferred PHPUnit/browser |
| AC28 | Normal application boot and route inspection | Passport/OAuth token/client/discovery endpoints remain unavailable; no clients created by user/application operations. Preserve existing OAuth-preparation tests |

Stop at the contract. Implementing these acceptance criteria and their supporting code is a separate task.
