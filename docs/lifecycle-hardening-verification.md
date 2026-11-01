# Central lifecycle hardening verification boundaries

## Files changed

- `backend/app/Models/User.php`
- `backend/app/Services/UserService.php`
- `backend/app/Services/AuthService.php`
- `backend/app/Services/ApplicationAccessService.php`
- `backend/app/Http/Middleware/EnsureAccountIsActive.php`
- `backend/app/Listeners/StampAuthenticatedSession.php` (new)
- `backend/database/migrations/2026_09_23_000001_add_session_version_to_users.php` (new)
- `backend/tests/TestCase.php`
- `backend/tests/Feature/AuthorizationTest.php`
- `backend/tests/Feature/AuditLoggingTest.php`
- `backend/tests/Feature/Users/LifecycleHardeningTest.php` (new)
- `backend/tests/Feature/Auth/LifecycleSessionTest.php` (new)
- `docs/user-lifecycle-contract.md` (existing supplied document, initially untracked)
- `docs/lifecycle-hardening-verification.md` (new)

The initially untracked implementation-plan document was read but not changed.

## Executed checks (2026-09-27)

The implementation and focused suites were already present as uncommitted workspace changes when this task resumed. They were inspected before edits. This pass expanded authorization coverage to all three lifecycle endpoints, added an HTTP protected-field regression, corrected the stale administrator-safety paragraph in the contract, and reran the required checks.

| Check | Result |
| --- | --- |
| Targeted PHPUnit: LifecycleHardeningTest, LifecycleSessionTest, AuthenticationTest, UserManagementTest, AuditLoggingTest, AuthorizationTest | Passed: 120 tests, 541 assertions |
| `php artisan test` | Passed: 158 tests, 769 assertions |
| `php vendor/bin/pint --test` | Passed |

Tests used the existing isolated SQLite setup. No PostgreSQL concurrency, Playwright, frontend implementation, OAuth flow or HRM operation was run.

## Implemented scope

The existing deactivate, suspend and reactivate operations enforce the reviewed transition table, authorization, self-disable protection and transactional audit. Ordinary user updates allow only name, email and password; changed normalized email clears verification. No delete, demote, restore, review, frontend, federation or HRM workflow is introduced.

All actual transitions to inactive/suspended advance a persisted session version and rotate the remember token in the audit transaction. Reactivation preserves that version. A synchronous Login listener stamps password/registration/remember authentication from the validated model snapshot. Middleware rejects current disabled/deleted users and mismatched or absent session versions. Old session storage entries may remain until expiry but cannot authorize protected requests. Audit failure rolls back status, version and remember-token changes together.

Apply `2026_09_23_000001_add_session_version_to_users` before serving the updated code. Existing unversioned sessions must reauthenticate; existing valid remember credentials remain eligible until revoked. Deployment/migration on a configured development or production database was not part of this task.

## Minimum administrator invariant

`UserService::changeStatus()` obtains `SELECT ... FOR UPDATE` locks on the actor and target rows in ascending primary-key order, in the same transaction as mutation/audit. It then rechecks the persisted actor's active, non-deleted administrator eligibility and the actor snapshot's session version. Self-disable is forbidden.

Consequently every committed disabling operation has a distinct active administrator whose row remains locked and active through commit. It cannot remove the last administrator. Two administrators trying to disable each other acquire the same ordered locks; the loser must re-read an ineligible actor and reject. A separate unlocked administrator count is neither used nor needed for this restricted operation set. PostgreSQL row locks are required for the concurrency argument. All lifecycle entry points must continue to use this service.

This guarantee does not cover raw SQL, arbitrary Eloquent writes, seeders, or future delete/demote operations. Those are not exposed management workflows. Future delete/demote/restore/operator recovery requires a reviewed extension; arbitrary direct writes can bypass any service invariant. No operational recovery procedure or suspension-review evidence collection is invented here.

## Automated backend coverage

`LifecycleHardeningTest` covers transitions/no-ops/rejections, guest and ineligible actors, deleted/stale models, self-disable with one/multiple admins, stale actor authorization, all 36 eligibility combinations, email verification, protected update fields, retained related records, and audit rollback including session credentials.

`LifecycleSessionTest` obtains session payloads through actual password logins and replays two independently captured authentication payloads through the web/Sanctum middleware with guard caches cleared. One payload remains idle through disabling/reactivation. It checks denial, fresh login, missing version, deleted identity, and actual encrypted remember-cookie authentication before and after revocation. These are deterministic backend tests using the existing SQLite/array-session setup; they do not simulate simultaneous browser processes or PostgreSQL locks.

## PostgreSQL integration checks still required

Run these separately against a disposable PostgreSQL database with the IAM migrations, two independent connections/processes, committed fixtures and production transaction isolation (normally READ COMMITTED). Do not use the shared development/production database, SQLite or a test-wide rollback transaction. No PostgreSQL concurrency run was performed in this task.

1. **Mutual disable:** create only active admins A and B. Pause A's transaction after ordered actor/target locks, start B disabling A and verify it blocks. Commit A disabling B, then verify B's request rejects, A stays active, and only A's transition audit exists. Repeat reversing lock winner and using suspend.
2. **Credential login versus disabling:** pause credential login with its user lock held, start disabling and verify it blocks. Release login, commit disabling, then replay its session and remember cookie before and after reactivation: both must fail. Reverse the winner: login must see the disabled state and fail. Include an initially empty remember token to detect a late token write.
3. **Audit failure while locked:** make the audit insert fail after mutation; verify from the other connection that status/version/remember token remain unchanged and a blocked subsequent lifecycle operation can proceed after rollback.
4. **Remember recall interleaving:** pause after the provider reads an old valid remember token; disable/reactivate in another connection; resume the recall. Its stamped old version must be rejected by protected middleware.

These cases identify the PostgreSQL-specific validation gap; passing SQLite tests does not prove the concurrency claims. There is no automated multi-process PostgreSQL harness in the existing suite, and this phase does not claim one was run.

## Playwright follow-up

Use two independent browser contexts plus an admin context. Log the target in on both; keep one offline through deactivate/reactivate, then repeat with suspend/release. Verify both old browser sessions and old remember cookies fail, fresh interactive login works, and the client clears authenticated state and returns to login on rejection. This remains separate browser verification; no frontend work or OAuth flow was run here.

Deletion/restoration remain deferred. Current deleted identities cannot authenticate or obtain eligibility, but a future restore operation must revoke pre-deletion session versions and remembered credentials atomically. Do not expose raw model restore as that operation. Application grant concurrency, application-access UI, review/approval UI and operator recovery are outside this phase.
