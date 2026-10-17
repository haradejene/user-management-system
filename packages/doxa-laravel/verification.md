# B2 AC24 diagnostic disclosure correction

Verified 2026-10-17 on the uncommitted B2 preparation. Both reported `var_export()` disclosures were reproduced by permanent sentinel regressions before the correction: the policy exported its configured client secret and the successful result exported access/refresh tokens. Those two tests initially failed as expected; they now pass without magic-method workarounds or secret-bearing wrappers.

## Structural correction and internal API

- `ManagedHttpPolicy` retains only the validated integer timeout copied from `DoxaConfig`; it stores no configuration reference, secret, response, callback or credential. All three timeout options still use the same value. TLS, redirect/cookie/debug prohibition, byte/deadline checks, stream closure and conservative error classification remain unchanged.
- `ManagedTransportResult` contains only outcome/status/failure and, after successful validation, a `RefreshResponse` containing scope/expiry metadata. Its factories no longer accept or retain a raw body. No plaintext credential-bearing input is reachable through its properties, including recursively exported properties.
- `inspectRefresh(receivedResponse, responseStartedAt, oldRefreshToken, trustedBinding, tokenStartedAt, safetyMargin)` reads the bounded body and invokes the unchanged strict parser within the same transient call, before closing the stream. Raw content is available for validation, then discarded rather than returned or retained. Parser errors become sanitized typed failures. `validatedRefresh()` retrieves only already-validated metadata and accepts no credentials. The former delayed `validateRefresh(...)` operation is removed from this uncommitted internal API and its call sites are updated. `inspect()` remains classification-only: it retains no body and cannot supply a validated refresh value. Callers requiring refresh validation must use `inspectRefresh()` in the original read pass.

The object-graph fix does not depend on `__debugInfo()`, `__serialize()`, encryption, static secret caches or another body-bearing object. Existing protected result/parser JSON and PHP serialization prohibitions remain. The timeout-only policy can serialize/export only non-secret policy data. No sender, dispatch authority, credential handoff, store mutation, lazy refresh, resource API, access check or pending-link promotion was added. Milestone A/B1, identity-only APIs, parser rules, dependencies, schema, configuration and service registrations are unchanged.

## Current executed checks

PHP 8.2.12, Laravel 12.69.3 checked-in baseline, PHPUnit 11.5.56 and persistent SQLite 3.39.2:

| Check | Actual result |
| --- | --- |
| Four disclosure/retention regressions in `ManagedHttpPolicyTest` | Passed: 4 tests / 66 assertions |
| `php vendor/bin/phpunit --filter 'ManagedHttpPolicyTest\|ManagedRefreshMetadataTest\|RefreshResponseTest' --do-not-cache-result` | Passed: 73 tests / 414 assertions |
| `php vendor/bin/phpunit --do-not-cache-result` | Passed: 393 tests / 2,670 assertions; includes existing login/callback, SQLite storage, binding/cleanup and B1 process/race regressions |
| `php vendor/bin/phpstan analyse --no-progress` | Passed; no errors |
| `php vendor/bin/pint --test` | Passed, package-wide |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed |
| `git diff --check` and new-file whitespace checks | Passed |

The permanent tests check distinctive client/access/refresh sentinels against `var_export()`, `print_r()` and recursive private-property inspection. They also exercise successful parsing, metadata-only inspection, malformed JSON, unexpected ID tokens, unchanged rotation, closed response streams, typed failure categories, JSON/PHP serialization guards and sanitized exception exports. Separate PHP subprocesses with exception arguments enabled cover both metadata-only outward errors and direct parser errors; direct parsing retains its sensitive-parameter trace protection. Existing B2 HTTP-error cases also check exported result graphs. The final baseline PHPUnit log is retained outside the repository at `%TEMP%\doxa-b2-ac24-m23dnsbj\baseline-phpunit.log`.

Laravel 10/11, dependency-floor, PostgreSQL and the full five-profile matrix were not rerun. There was no live IAM call, production refresh dispatch or complete refresh lifecycle test. PostgreSQL and persistent SQLite remain the managed-store targets; MySQL/MariaDB remains unsupported. Earlier validation below is historical and does not establish that the pre-fix diagnostic graphs were safe. Compatibility artifacts and the four unrelated root documents are unchanged. Nothing was staged, committed or pushed.

# Historical B2 preparation (before AC24 diagnostic correction)

Verified 2026-10-17 on the committed B1 foundation (`ce46fa86c1745d76f216de064ccf57f0d8b786dd`). This is deliberately non-dispatchable preparation for contract v1.3 AC24, AC28, AC31 and AC33 and sections 20-23, not complete managed-refresh conformance.

## Implemented boundary

- `ProviderMetadata::assertManagedRefresh` requires explicitly advertised `refresh_token`, the configured issuer and compatible registered authentication method. Existing HTTPS/credential/fragment URL checks apply; trusted discovery may advertise a different HTTPS token origin. No new endpoint override is accepted. Refresh support remains optional for ordinary code login. Missing/unknown legacy refresh evidence fails closed for the new capability; existing baseline discovery requirements are unchanged.
- `ManagedHttpPolicy` defines the existing bounded timeout (1-60 seconds), TLS verification, no redirects/cookies/debugging, streaming and a 262,144-byte body ceiling. Its only response operation inspects an already-received PSR response, closes the stream and rejects unsupported media types, invalid lengths, truncation, overdue/broken streams and redirects. It has no HTTP client, request builder, handler or send method. These are response/policy tests, not proof of network timeout enforcement.
- `ManagedDispatchOutcome`, `ManagedTransportResult`, `RefreshFailure` and `RefreshResponseException` separate local non-dispatch, received HTTP headers and uncertainty. Generic network failure must be represented as uncertain; 5xx never proves rollback or permits retry. Recognized 400/401 `invalid_grant` and `invalid_client` are categorized without retaining provider descriptions or inventing precise causes. Result classifications are constructible data, never dispatch authority or proof of non-dispatch.
- `RefreshResponse` strictly validates bounded JSON objects, nonempty typed tokens, case-insensitive Bearer, mandatory distinct rotated refresh token, integer `expires_in` in 1-2,147,483,647 and usability at the request-start-based expiry. The safety margin defaults to 60 seconds and accepts only 0-60. The immutable absolute context deadline must remain live and is preserved separately; access expiry never extends that deadline. Refresh expiry remains unknown (`null`), matching the inspected IAM response rather than decoding credentials or inventing provider lifetime.
- Response scopes must be well-formed, unique and no broader than the prior grant/original ceiling. Omitted scope inherits the prior set; narrowing is accepted, including loss of `openid`, which cannot authorize a later operation requiring it. The original ceiling must preserve OIDC provenance. Optional issuer/client/subject metadata must match the trusted binding. Any `id_token` or `error` field in a success is rejected, even if null. Tokens are discarded by the parser after validation: its protected value exposes metadata only, has no token getter or persistence operation and cannot be serialized. The bounded input result retains no publicly accessible response body.

B2 registers no production sender/service and adds no store mutation, credential acquisition, B1 state-machine change or use of `RefreshAttempt` as authority. Existing `Transport`, code exchange, callback, identity, dependencies, configuration and migrations are unchanged. No retry middleware is installed. B3 must supply the exclusively issued single-use dispatch authority, protected credential handoff, dedicated no-retry client, request construction/client authentication, durable coordination, terminal cleanup and same-attempt recovery before production sending can be connected. Reading a persisted `dispatched` phase is insufficient. No lazy refresh, resource requests, application-access operation or pending-link promotion is implemented.

## Current executed checks

PHP 8.2.12, Laravel 12.69.3 checked-in baseline, PHPUnit 11.5.56 and SQLite 3.39.2:

| Check | Actual result |
| --- | --- |
| `php vendor/bin/phpunit --filter 'ManagedRefreshMetadataTest|RefreshResponseTest|ManagedHttpPolicyTest' --do-not-cache-result` | Passed: 69 tests / 320 assertions |
| Final frozen-source `php vendor/bin/phpunit --do-not-cache-result` | Passed: 389 tests / 2,576 assertions; includes existing login/callback, persistent SQLite storage, binding, cleanup and B1 process/race regressions |
| `php vendor/bin/phpstan analyse --no-progress` | Passed; no errors |
| `php vendor/bin/pint --test` | Passed, package-wide |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed |
| `git diff --check` and new-file whitespace inspection | Passed |

The new tests cover optional refresh discovery through a real fixture login, unsafe endpoints, foreign HTTPS token origin, unknown legacy capability, response/status/body classification, token/scope/binding/expiry failures, rotation, serialization and separate-process traces with `zend.exception_ignore_args=0`. All HTTP responses are deterministic fixtures. Initial test development exposed unprotected test-helper arguments (corrected with sensitive-parameter boundaries), an unjustified query-URL rejection expectation (removed to preserve existing endpoint rules), and PHPStan findings concerning stream side effects and unused retained credentials. The final source explicitly marks stream reads impure, discards parsed credentials and denies unknown legacy capability. No assertions in pre-existing tests were changed or skipped.

Laravel 10/11, dependency-floor, the five-profile matrix and PostgreSQL were not rerun for B2 preparation. Their earlier evidence below is historical, not current B2 validation. Laravel 10/11/12 and PHP ^8.2 constraints are unchanged. PostgreSQL and persistent SQLite remain the managed-store targets; MySQL/MariaDB remains unsupported. There was no live IAM request, real refresh dispatch, network-dispatch recovery test, production database operation or complete refresh lifecycle test. B1 store/schema/concurrency code was not changed. Existing compatibility artifacts and the four unrelated untracked root documents were preserved. Nothing was staged, committed or pushed.

# Durable refresh coordination — Milestone B1

B1 implements internal storage/lifecycle primitives against Universal Contract v1.3 sections 20–23, with storage-focused coverage for AC25–AC26, AC29–AC32 and AC36–AC37. It is not complete refresh or managed-capability conformance. No refresh HTTP exchange, lazy-refresh orchestration, resource request, application-access check, pending-link refresh/promotion, or IAM/HRM implementation is provided. Identity-only APIs and all existing tests remain unchanged.

## State machine and durability

`RefreshCredentialStore` extends the internal store capability without changing the Milestone A adapter interface. `CredentialManager` revalidates live host binding for every internal `refresh` operation. Completion proof is required for every owned record, including refreshing and terminal states; an identifier or constructed attempt is not authorization.

| Transition | Durable condition |
| --- | --- |
| `active -> refreshing/claimed` | Completed ordinary owner/proof, correct issuer/client/subject/purpose/grant, expected version, unusable-or-near-expiry access and an available nonexpired refresh credential. Random 256-bit attempt ID; version increments. |
| `refreshing/claimed -> refreshing/dispatched` | Exact context/attempt/version/phase and live deadline; version increments before future dispatch is allowed. The returned attempt records dispatch intent, not a network exchange. This transition is never acknowledged twice. |
| `refreshing/claimed -> active` | Explicit release, or expired-claim cleanup while original context lifetime remains valid. Durable absence of dispatch intent and conditional version update fence every old dispatcher. |
| `refreshing/dispatched -> active` | Exact live attempt/version and internally validated replacement material. Atomically replace both credentials, scopes and expiry; require a distinct refresh token and no scope expansion; retain original owner/proof and absolute lifetime. |
| `refreshing/dispatched -> unusable` | Explicit failure or overdue-attempt recovery; erase credentials and record sanitized uncertainty. No old-token retry or lease-based redispatch. |
| Any live state to terminal | Owner-authorized deletion or expiry increments the version and erases credentials. No stale claim/completion/compensation can revive it. A never-dispatched claim whose context lifetime ended expires normally. |

The existing indexed `expires_at` becomes the bounded attempt deadline (default 30 seconds, trusted internal range 1–60). Its original context limit is integrity-protected inside the encrypted attempt metadata. No schema/migration/index change is needed. Recovery never extends absolute lifetime. Cleanup remains an explicit host-scheduled operation.

Lost claim or dispatch-intent acknowledgements leave durable nonusable phase evidence. Storage/reconciliation failure fails closed. After completion acknowledgement loss, only the exact already committed response/attempt/version can be acknowledged by a read-only reconciliation; no token is resubmitted. Reconciliation rejects ambient transactions, terminal state and newer versions. B2 must still supply HTTP/protocol validation, safe dispatch orchestration and categorized provider handling. B1 returns no raw credentials and does not provide a token-acquisition API.

## Regression scope

Before the subsequent deterministic race regression, `tests/Integration/RefreshCoordinationTest.php` added 29 tests/cases: atomic replacement and preservation of owner/lifetime; pre-dispatch release; duplicate/out-of-order transitions; owner/session/issuer/client/subject/purpose/proof rejection; forged attempts/context/version; malformed or nonrotating replacements; committed writes with lost acknowledgements at claim/intent/completion, including unavailable reconciliation; exact-response reconciliation and ambient-transaction rejection; later-version/stale-worker fencing; absolute expiry; protected attempt diagnostics; independent-process claim/delete/completion/cleanup races; and process exit after claim or dispatch intent. Fixtures never perform refresh HTTP requests. Process tests establish database state-machine behavior, not end-to-end HTTP uncertainty recovery.

## B1 validation results (before the latest test-only addition)

Final frozen-PHP-source validation passed on PHP 8.2.12, SQLite 3.39.2 and an isolated PostgreSQL 18.4 cluster. No IAM or HRM database was used. All full-suite JUnit reports have zero errors, failures and skips; PostgreSQL runs include independent processes/connections. The original 290 Milestone A tests remain unchanged; B1 adds 29 cases / 135 assertions. The package PostgreSQL runner filter now includes `RefreshCoordinationTest`; its Python syntax check passed.

| Profile | Full SQLite-backed SDK suite | PostgreSQL managed-store/process suite | PHPStan / Pint / strict Composer / platform |
| --- | --- | --- | --- |
| Laravel 10.50.2 | 319 tests / 2,225 assertions | 96 tests / 324 assertions | Passed |
| Laravel 11.57.0 | 319 tests / 2,225 assertions | 96 tests / 324 assertions | Passed |
| Laravel 12.69.3 | 319 tests / 2,225 assertions | 96 tests / 324 assertions | Passed |
| Dependency floors (Laravel 10.50.2, Guzzle 7.8.2, JWT 7.1.0) | 319 tests / 2,225 assertions | 96 tests / 324 assertions | Passed |
| Restored checked-in Laravel 12 baseline | 319 tests / 2,225 assertions | 96 tests / 324 assertions | Passed |

The normalized PHP source/test fingerprint for that earlier matrix is `8a760cf133584a8750f09ec8fd7e54cd07efc4beb0f798a1da216e49803736af`: SHA-256 over sorted package-relative PHP paths followed by their LF-normalized contents under `src`, `tests`, `config` and `database`. Working-tree and isolated-validation-copy fingerprints matched. Documentation and Python tooling are outside this PHP fingerprint. Existing profile manifests/locks were reused without resolution, dependency upgrades or advisory-policy changes. Baseline Composer validation used unqualified `--strict`; historical fixtures used the existing deliberate-pin `--no-check-all --no-check-publish` flags. No new dependency audit is claimed.

Validation ran in a temporary package copy, with read-only Composer cache use, to preserve all existing `.compatibility` evidence. Local review logs, JUnit and results are retained outside the repository at `%TEMP%\doxa-b1-m5kfghkg\final`; preliminary runs remain separately retained in its parent. Initial Pint formatting issues were fixed. PostgreSQL absolute-path initialization failed before tests and succeeded with a relative temporary path; an output-variable error in the temporary final-run logger was corrected before the successful final rerun. No final SDK suite/check failed or was skipped. `git diff --check` and new-file whitespace checks passed. Content hashes confirmed no changes/additions/removals in existing compatibility evidence or unrelated root documents. Nothing was staged, committed or pushed.

### Subsequent deterministic cleanup/completion regression

The later test-only addition uses `RefreshUpdateBarrier` and independent PDO connections to pause completion after live validation, run legitimate cleanup, and resume the real conditional update. It asserts zero affected rows, terminal `unusable` state, both credentials erased, unchanged terminal payload/ciphertext, scopes and expiry, and rejected stale completion/restoration. Production code was unchanged by this addition.

| Engine | New regression | Related managed-storage, binding, credential-race and refresh regressions |
| --- | --- | --- |
| Persistent SQLite | Passed: 1 test / 31 assertions | Passed: 97 tests / 355 assertions |
| Isolated PostgreSQL | Passed: 1 test / 31 assertions | Passed: 97 tests / 355 assertions |

These targeted runs used the checked-in Laravel 12 baseline. Targeted Pint and `git diff --check` passed. The full five-profile matrix, PHPStan, Composer/platform checks and other quality checks were not rerun after this test-only addition; the matrix totals and fingerprint above describe the earlier source snapshot, not the final test tree. No inferred full-suite totals or replacement fingerprint are claimed. Existing compatibility artifacts were unchanged and the isolated PostgreSQL server was stopped afterward.

No production database is certified by these tests. MySQL/MariaDB remains unsupported and fails readiness. PostgreSQL and persistent SQLite remain the supported managed-store targets. The existing offline backup invalidation procedure is still required; B1 adds no rollback detector.

# Historical Milestone A binding security remediation

Verified 2026-10-16. This record supersedes the initial Milestone A binding/readiness behavior below. SDK package only; identity-only APIs, identity fields, OAuth validation and one-time exchange are unchanged. No refresh, rotation, resource/API access, access check, provider revocation, pending-link promotion or IAM/HRM implementation was added. The universal contract and original 219 tests remain unchanged; nothing was staged, committed or pushed.

## Binding completion and compensation

The old implementation equated an active row with confirmed binding and compensated by deleting its reloaded latest version. A lost write acknowledgement plus unavailable compensation could leave a restorable record; a stale binder could delete another worker's successful binding.

The corrected flow claims `awaiting_binding -> binding` with a CSPRNG 256-bit attempt ID and independent completion-proof hash before host mapping verification. The nonusable claim retains the unbound deadline. The trusted host adapter must verify the already committed exact mapping and permitted session transition. `binding -> active` requires the same attempt, proof hash, expected version, ordinary purpose, live state and expiry. The proof preimage is never stored in the credential record. Only an acknowledged active write releases a replacement opaque context containing that preimage. `bindCredentialContext` now returns that replacement context; hosts must replace their protected server reference after successful return. An active row without a released proof is insufficient for restore/use, even after storage recovers or a process restarts. A proof still supplies no ownership authorization: restoration independently revalidates current host ownership/session/issuer/client/lifecycle. No old authorization transaction or code is reopened. Recovery of an unreleased attempt requires fresh authorization, not automatic promotion.

`abandonBinding` may erase only the exact attempt/version in binding/active state. A losing CAS has a different attempt or version and cannot affect the winner; no broad reload-and-delete exists. Mapping failure retires its owned claim. If compensation is unavailable, no completion proof is released, so the record remains inaccessible until bounded cleanup. A crash after the claim or after activation also releases no completed reference. Stale/terminal/missing records cannot be revived. Pending-link purpose remains unpromotable. Earlier uncommitted ID-only active records fail closed and require invalidation/fresh authorization; no table migration is required for the encrypted attempt metadata.

## Schema readiness and backup recovery

Preflight uses native SQLite/PostgreSQL catalogs on the write connection. It verifies required columns, nonnullable required fields, integer version/expiry fields, primary context-ID key, unique authorization provenance and standalone status/expiry/purge indexes. Partial/expression/incomplete indexes do not establish these invariants. A malformed table rejects managed login before discovery/redirect; no schema is automatically repaired. The official published migration is also executed by the provider integration test. Other database drivers, including MySQL/MariaDB, currently reject managed readiness rather than claiming unverified compatibility.

README's **Offline backup restoration and rollback** procedure requires a durable external traffic/readiness gate, stopped/drained workers, deletion of every restored credential row on all serving copies, invalidation of host references/sessions/linking and OAuth transaction cache state, fresh-process schema/zero-row/old-reference verification, and fresh authorization before reopening. Interruption leaves the gate closed and requires repeating invalidation. Stale credential rows, completed references/proofs, sessions and pending authorization/linking state must never be copied back afterward. There is no automatic rollback detector. A real deployment's inability to quiesce, invalidate or verify all copies remains a deployment blocker; this document does not certify HRM operations.

## Exact additional regression coverage

`tests/Integration/BindingCompletionTest.php` adds:

- `test_lost_acknowledgement_and_compensation_outage_cannot_restore_after_recovery`: committed active write, unavailable reconciliation/erasure and fresh-service restoration denial.
- `test_failed_host_mapping_retires_only_its_durable_binding_attempt`: committed mapping failure terminalizes only the claimed attempt and erases credentials.
- `test_completion_proof_and_attempt_are_immutable_and_stale_reconciliation_is_harmless`: missing/forged proof and foreign owner rejection; wrong-attempt/version reconciliation preserves success; proof preimage absent from record metadata/debug output.
- `test_schema_without_unique_provenance_fails_before_authorization`: expected columns without required uniqueness reject before discovery/exchange; official schema passes.
- `test_offline_restore_invalidation_removes_all_old_references_and_fences_stale_writes`: deliberately restores an integrity-valid old active row, reproduces rollback acceptance, then verifies offline row erasure prevents restoration and stale reconciliation cannot recreate it.
- `test_independent_manager_binders_preserve_the_winner_and_process_death_releases_no_proof`: two independent processes synchronize after reading the original version; losing manager executes its normal compensation path; winner remains restorable only by its owner. Separate workers exit immediately after claim and activation, proving neither releases usable completion proof.

The pre-existing lost-acknowledgement compensation test remains and passes. New manager workers complement, rather than replace, existing store/process races. Managed tests adopt the returned completed reference without removing security assertions. All original tracked test/fixture files remain byte-equivalent to HEAD after line-ending normalization.

## Frozen-source matrix

| Environment | Full suite (SQLite-backed credential fixtures) | PostgreSQL credential/process suite | PHPStan / Pint / strict Composer / platform |
| --- | --- | --- | --- |
| Laravel 10.50.2 | 290 tests / 2,090 assertions | 67 tests / 189 assertions | Passed |
| Laravel 11.57.0 | 290 tests / 2,090 assertions | 67 tests / 189 assertions | Passed |
| Laravel 12.69.3 | 290 tests / 2,090 assertions | 67 tests / 189 assertions | Passed |
| Dependency floors (Laravel 10.50.2, Guzzle 7.8.2, JWT 7.1.0) | 290 tests / 2,090 assertions | 67 tests / 189 assertions | Passed |
| Restored checked-in Laravel 12 baseline | 290 tests / 2,090 assertions | 67 tests / 189 assertions | Passed |

Latest cleanup validation evidence is retained in ignored `.compatibility/schema-nullability/`: per-profile JUnit reports, PostgreSQL logs, `results.json`, `summary.json` and `source.sha256`. All five profiles above passed with zero errors, failures or skips; PHPStan, Pint, strict Composer validation and platform checks passed. The recorded cleanup source SHA-256 is `b5079b4e68e243fb66ca708440ffcd42c2084639630d0c550a3a033e79a396e1`. One dependency-floor installation timed out; the failed evidence was retained and the unchanged retry passed.

Previous binding-hardening runs passed 289 tests / 2,085 assertions and PostgreSQL 66 tests / 184 assertions per profile, with zero errors, failures or skips. PHP remains 8.2.12; SQLite 3.39.2 and PostgreSQL 18.4 remain the tested database versions. That earlier source fingerprint was `dc25bd4e8b17f4f904efe19e7f49c83f55362dada2bf1097e710268ca757e910`. The first remediation matrix passed before the durable-claim refinement; the final matrix was rerun on the refined source. One final Laravel 12 attempt was interrupted with 13h50m elapsed time and three unchanged PublicErrorTraceTest subprocess timeouts (not assertion failures). Its log/JUnit are retained as `laravel12-interrupted-*`; the unchanged focused trace suite then passed 25 tests / 1,298 assertions, and the complete Laravel 12/floors/baseline profiles were rerun successfully without changing tests/timeouts. Earlier Laravel 10/11 runs used the identical final fingerprint.

Existing matching Composer locks/audit evidence were reused; no dependency resolution or advisory policy was altered. Historical advisory findings remain 10/4/13 for Laravel 10/11/floors and zero for Laravel 12/baseline; this is not a fresh audit or a security approval of historical dependencies. An isolated PostgreSQL cluster on port 55439 served these tests; no IAM/HRM database was accessed or changed. No production database/version is certified. Read-only HRM inspection finds local `.env` selecting PostgreSQL, while default/example configuration selects MySQL. Actual production configuration remains unconfirmed; MySQL/MariaDB readiness/deployment is blocked pending an implemented and tested driver path.

Complete changes and amendment-only changes are available in ignored `.compatibility/managed-credentials/milestone-a.patch` and `binding-hardening.patch`, with matching stat files. Source freeze excludes documentation. Backup recovery must be rehearsed under the real host gate/topology. Future Milestone B must preserve the completion-proof plus trusted ownership gate before any credential use, along with its separately required refresh/uncertainty/access-check conformance. None of that runtime behavior is implemented here.

# Historical initial Milestone A record

# Managed credential storage — Milestone A

Implemented 2026-10-15 against Universal Contract v1.3 sections 19–26. This is opt-in credential retention and storage/lifecycle groundwork, not full v1.3 managed-capability conformance. No refresh, rotation, IAM resource/access-check operation, provider revocation or identity-linking confirmation is implemented. No IAM/HRM runtime, universal contract or existing security test was changed. Nothing was staged, committed or pushed.

## API and ownership

`DoxaClient` adds `beginManagedLogin(ManagedLoginPolicy, ?AuthorizationOptions)`, `handleManagedCallback(Request): ManagedLoginResult`, `bindCredentialContext`, `deleteCredentialContext` and owner-checking `restoreCredentialContext`. Existing identity-only signatures, authorization options and `DoxaIdentity` are preserved. The result exposes only identity and an opaque context; protected result/context/policy values refuse PHP/JSON serialization and redact debugging. A 256-bit opaque server reference is not ownership proof.

The host must explicitly register `CredentialHostBinding`. The adapter resolves authorized server records, verifies live ownership/session/issuer/subject/purpose, and resolves the committed exact local mapping for ordinary binding. No public binding method accepts a user ID or boolean mapping proof. Session regeneration at initial binding requires explicit host verification. Pending linking binds the initiating administrator, browser/session, link transaction, immutable target and short deadline; it cannot be completed/promoted in this milestone. No automatic account/session creation occurs.

## Persistence and failure model

The explicitly published migration creates a separate credential table with primary context ID, unique authorization provenance, indexed lifecycle/expiry/tombstone fields, monotonic version, key ID and encrypted payload. The payload binds identity, client, scopes, purpose, ownership, expiry and lifecycle to the record ID/version. Clear operational columns are checked against authenticated encrypted content. Database conditional updates check ID/version/state and, for live transitions, expiry. Stale writes cannot resurrect terminal/missing records. The adapter returns metadata only, never credentials.

Laravel's established AES-256-CBC/MAC primitive protects JSON using an explicitly configured dedicated 32-byte key and key identifier. There is no APP_KEY fallback or automatic key rotation. Hosts may configure an approved cipher adapter; backup/key retention and controlled rotation remain operational responsibilities. An isolated database connection suppresses Laravel SQL events/query logs; external instrumentation must also exclude credential data.

Managed policy is persisted in the protected authorization transaction before redirect. Handler mismatch fails before exchange; legacy transactions remain identity-only. Token responses remain internal; managed expiry/optional refresh fields and scope/error omission semantics are checked. Credential staging requires both exact exchanged-token provenance and an identity proof from that same validated ID token/claimed transaction. This adds no raw-token getter and does not change identity fields.

After existing full validation and optional UserInfo, callback storage progresses from nonusable `staged`, through confirmed transaction finalization, to `awaiting_binding` or `pending_link`. Only durable confirmation returns a result. Failure returns no success/fallback and never re-exchanges a code. The original transaction secrets are not retained by a completed context. Post-commit/finalization/activation fault injection verifies compensation or nonusable staged-orphan cleanup. Store outages fail closed; hosts must arrange cleanup/reconciliation when storage recovers, and preset deadlines bound abandoned retention. Local erasure does not revoke IAM tokens.

Deletion erases secret fields, increments the version and keeps a bounded tombstone. Cleanup handles staged/unbound/pending/active expiry and later tombstone purge; tombstones not yet due cannot starve live-expiry batches. The adapter rejects ambient transactions and in-memory databases rather than reporting durable success that a caller can roll back or lose at process exit. Laravel URL/read-write connection configuration is resolved before creating the isolated connection. Ordinary binding verifies an already committed host mapping; cross-store pending-link completion waits for Milestone B.

## Acceptance coverage

| Contract criteria | Added verification |
| --- | --- |
| AC22 | Identity-only needs no managed configuration/table/adapter; trusted opt-in and policy retention; hostile callback parameters cannot opt in; mismatched handlers make zero exchanges. |
| AC23 | Durable stage/finalize/activate ordering; insert, post-commit, finish and activation faults; no result/fallback/re-exchange; actual worker exit after staging and orphan cleanup. |
| AC24 | Identity remains credential-free; protected serialization/debugging; argument-enabled recursive error-trace inspection rejects credentials/key/nonce/verifier leakage; no chained low-level errors. |
| AC25 | Explicit key/table/adapter preflight, wrong-key/integrity failures, ciphertext/column substitution, persistence through independent processes, no SQL logging/events, ambient rollback protection. |
| AC26 | Session/user/issuer/client/subject and exchanged-token identity substitution failures; immutable owner binding; pending initiator/link/target/deadline checks; no pending completion. |
| AC32/AC36/AC37, storage subset | Idempotent local deletion, expired/cancelled contexts, lost binding response, stale-write fences, independent-process bind/delete and bind/cleanup races, terminal-state preservation. |
| AC01–AC21 | All pre-existing tests retained and run; no assertion removed, weakened or skipped. |

## Validation results

Final frozen-source verification completed on PHP **8.2.12**. All runs have **zero failures, errors or skipped tests**. The complete suite retains the original 219 tests and adds 64 tests/cases. Dedicated PostgreSQL runs include independent-process races, restart and staged-callback process-death coverage, rather than sequential-only simulations.

| Environment | Laravel | Full SQLite-backed suite | Dedicated PostgreSQL suite | PHPStan / Pint / strict manifest / platform |
| --- | --- | --- | --- | --- |
| Laravel 10 | 10.50.2 | 283 tests / 2,049 assertions | 60 tests / 148 assertions | Passed |
| Laravel 11 | 11.57.0 | 283 tests / 2,049 assertions | 60 tests / 148 assertions | Passed |
| Laravel 12 | 12.69.3 | 283 tests / 2,049 assertions | 60 tests / 148 assertions | Passed |
| Dependency floors | 10.50.2; Guzzle 7.8.2; JWT 7.1.0 | 283 tests / 2,049 assertions | 60 tests / 148 assertions | Passed |
| Restored checked-in baseline | 12.69.3 | 283 tests / 2,049 assertions | 60 tests / 148 assertions | Passed |

The package manifest passes unqualified `composer validate --strict`; generated compatibility fixtures retain strict validation with `--no-check-all --no-check-publish` for their deliberate version pins. Every profile passes platform checks. Composer refreshed lock classification/hash after promoting the existing database component to runtime; no dependency versions changed. Its canonical phpdotenv repository URLs were refreshed as metadata. Historical-fixture lock refresh used the explicit command-only advisory exception; audits remain recorded: Laravel 10 **10**, Laravel 11 **4**, dependency floors **13**, Laravel 12/baseline **0** advisories. These are findings, not passing security audits for the historical profiles. The final source rerun reused matching locks/audits, performed every suite/check again, and restored baseline vendor.

Evidence is in ignored `.compatibility/managed-credentials/`: per-profile logs/JUnit, `results.json`, frozen source fingerprint, and complete SDK patch/stat including new files. `git diff --check` and reverse patch applicability checks pass. Source fingerprint (PHP source/config/migration/tests plus manifest/lock) is `84e16123e51bedd25c01e78db22d06080431288a54240ad5603475b20ac8f448`; it remained unchanged through the final matrix. Documentation changes after validation do not alter that fingerprint.

## Database assessment and limitations

SQLite test library: **3.39.2**. PostgreSQL test server: **18.4**, a temporary isolated cluster under the SDK's ignored `.compatibility` directory, on loopback port 55439. No IAM or HRM database/schema was migrated. Database races use independent PHP workers/connections and database compare-and-set operations, not sequential mocks or process-local locking.

Read-only HRM inspection found MySQL as `backend/config/database.php`'s default, consistent with the integration documentation's MySQL-specific VARBINARY identity constraints. InnoDB supports the required conditional updates, transactions, primary/unique indexes and long-text payloads; the SDK does exact identity comparisons in protected PHP data rather than relying on SQL text collations. **MySQL deployment compatibility is not claimed:** its adapter/concurrency/migration tests were not run, and actual HRM production engine/version was not queried. SQLite/PostgreSQL evidence does not establish MySQL compatibility or real HRM integration.

Historical locked environments report existing dependency advisories; audits are separate from passing protocol tests. No persistent advisory-ignore policy or dependency upgrade was introduced. Missing packages in one standalone analysis attempt during a dependency switch were an invocation-order issue; final matrix checks run sequentially against stable vendor installations. No tests were suppressed to recover.

## Remaining Milestone B work

Implement lazy refresh, mandatory rotation validation, durable refresh dispatch/claim/recovery and uncertain-outcome handling; add scoped internal credential use and `checkApplicationAccess` with its existing 403 ambiguity. Extend transport without weakening existing login error handling. Pending-link completion must then enforce explicit confirmation, a fresh authoritative access check, exact mapping/commit coordination and cleanup. Host logout/unlink/security/cancellation hooks and key/backup policy remain host integration responsibilities. This milestone supplies no HRM linking implementation or automatic federation logout.

Previous milestone records below are retained as historical evidence.

# Authorization-options milestone verification (historical)

Verified 2026-10-07 on PHP 8.2.12 and Composer 2.10.1 against Universal SDK Contract v1.1 (`../../docs/doxa-sdk-contract.md`). The contract was extended before finalizing the Laravel implementation. This milestone changes only the SDK and universal contract; it does not modify IAM, HRM, account linking, provisioning, role mapping or refresh-token support. No commits or pushes were made.

## Public API and security boundary

`DoxaClient::beginLogin(?AuthorizationOptions $options = null)` accepts the final, immutable `Doxa\Laravel\Authorization\AuthorizationOptions` value. `AuthorizationOptions::reauthenticate()` and `new AuthorizationOptions(prompt: 'login')` request exactly one outgoing `prompt=login`. Existing no-argument calls, explicit null and default options omit prompt and preserve the complete previous authorization URL construction. RFC 3986 encoding remains unchanged.

Only null and the exact string `login` are accepted. All other strings fail before discovery, transaction creation or redirect with `AuthorizationException` category `unsupported_authorization_option`, without echoing input or chaining an exception. Incorrect types and unknown named parameters are PHP programming errors before login side effects. There is no arbitrary query-parameter map or browser/callback adapter. Browser query values cannot override trusted options or security parameters; callback prompt values are ignored and scrubbed. Sensitive constructor/login arguments are redacted in public error traces.

Production changes are limited to the options value, optional public method argument and allowlisted addition in authorization URL construction. State, nonce, PKCE/S256, transaction creation/storage/session binding/expiry/provenance/claim/replay, code exchange, issuer/audience/signature/JWKS/ID-token and UserInfo validation are unchanged. No transaction/configuration fields, dependencies or token endpoint behavior changed. Both `composer.json` and `composer.lock` remain byte-for-byte unchanged from the task baseline.

`login` requests provider reauthentication; it does not request account selection or guarantee a different account, an `auth_time` claim or a particular authentication method. `select_account` is rejected today and requires provider/IAM support plus a future explicit contract and SDK extension. Live IAM/provider reauthentication was not exercised by these fixture-backed SDK tests.

## Current verification results

The pre-change suite passed with 194 tests / 1,710 assertions. The final suite adds 25 tests / 182 assertions, preserving all existing tests and security assertions. Every complete run below passed with 219 tests / 1,892 assertions and no errors, failures or skipped tests.

| Environment | Laravel | Testbench | PHPUnit | Complete PHPUnit | PHPStan / Pint / platform / manifest |
| --- | --- | --- | --- | --- | --- |
| Laravel 10 independent lock | 10.50.2 | 8.38.0 | 10.5.66 | 219 tests / 1,892 assertions | Passed |
| Laravel 11 independent lock | 11.57.0 | 9.18.0 | 11.5.57 | 219 tests / 1,892 assertions | Passed |
| Laravel 12 independent lock | 12.69.3 | 10.12.0 | 11.5.57 | 219 tests / 1,892 assertions | Passed |
| Restored checked-in baseline | 12.69.3 | 10.12.0 | 11.5.56 | 219 tests / 1,892 assertions | Passed |

The matrix reused existing independently resolved lockfiles via Composer install, sequentially because profiles share vendor. No security-blocking override or advisory suppression was used in this milestone. Matrix manifest checks use strict validation with `--no-check-all --no-check-publish` because exact fixture pins are intentional; the real package passes unqualified `composer validate --strict`. The checked-in baseline vendor was restored after the matrix.

Additional checks:

- Focused authorization-options, public-error-trace and service-provider tests: 53 tests / 1,442 assertions passed.
- Dedicated public-error/sensitive-parameter regression tests: 25 tests / 1,298 assertions passed. Dedicated independent-process concurrency tests: 5 tests / 76 assertions passed.
- Final Composer scripts `test`, `analyse` (PHPStan level 5) and `check-format` (Pint) passed.
- Composer install/lock/platform checks and package `composer validate --strict` passed.
- `git diff --check` passed; the prior IAM onboarding patch still reverse-applies cleanly, confirming that work was preserved.

Complete suites retain state/nonce/PKCE, binding/expiry, provenance, replay, file/SQLite process races and crashed-claimant protection, callback scrubbing, ID-token/JWKS/issuer/audience, UserInfo and safe-error coverage. New tests assert exact default URL bytes, prompt encoding and uniqueness, unsupported-value rejection, arbitrary parameter-map/named-parameter rejection, immutability, browser override rejection, callback prompt handling and unchanged query-bearing discovery endpoint rejection. A real Laravel web route also exercises explicit reauthentication with hostile browser parameters.

### Composer audit results and limitations

Composer audits completed after transient Packagist timeouts were retried. Laravel 10's historical compatibility lock reports 10 advisories (six Guzzle, four Laravel); Laravel 11 reports four Laravel advisories. Laravel 12 and the restored checked-in baseline report zero advisories and no abandoned packages. These pre-existing dependency findings are not passing security audits, despite passing SDK regression tests; this narrow milestone does not upgrade host dependencies or suppress advisory policy. The earlier compatibility record below contains the historical advisory identifiers and resolution context.

Generated manifests/locks, per-step logs, matrix JUnit XML and `results.json` are ignored local evidence in `.compatibility/authorization-options/` and `.compatibility/`. The local runner resumes completed checks and restores the baseline even after failure; it is verification evidence, not a new public SDK tool. Other PHP runtimes, live IAM, real HRM integration and Redis/PostgreSQL deployments were not tested.

## Exact files changed in this milestone

- `src/Authorization/AuthorizationOptions.php` (new allowlisted immutable options value).
- `src/Authorization/Authorization.php` (optional outgoing prompt only).
- `src/DoxaClient.php` (backward-compatible optional typed argument).
- `tests/Integration/AuthorizationOptionsTest.php` (new focused regressions).
- `tests/Integration/ServiceProviderTest.php` (Laravel web-route coverage).
- `tests/Integration/PublicErrorTraceTest.php` (safe unsupported-option trace coverage).
- `tests/Fixtures/public-error-trace.php` (sensitive-input trace fixture).
- `README.md` (API, example, trusted-input boundary and OIDC semantics).
- `verification.md` (this current record; previous evidence retained below).
- `../../docs/doxa-sdk-contract.md` (language-neutral v1.1 options contract and AC21).

# Historical Milestone 1 verification

## Laravel 10-12 compatibility implementation

Verified on PHP 8.2.12 with Composer 2.10.1. Only package dependency constraints, lock metadata, development tooling and documentation changed. No production source, public API, configuration, PHPUnit configuration, security assertions or test fixtures changed. No Laravel-version-specific production source changes were required. The universal OIDC contract and transaction/exception hardening remain unchanged. No HRM repository changes, commits or pushes were made.

Runtime Illuminate constraints now permit 10/11/12; HttpFoundation permits 6.4/7; Guzzle permits 7.8.2+. PHP remains ^8.2 and Firebase JWT remains ^7.1. Development Testbench permits 8/9/10, PHPUnit permits 10.5/11.5, Process permits 6.4/7, and database/filesystem permit Illuminate 10/11/12. No framework meta-package was added at runtime.

Each generated matrix profile used an independent Composer resolution. The checked-in lock remains the Laravel 12 development baseline: only its content hash changed, with no package-version changes. Generated manifests/locks and JUnit reports reside in ignored `.compatibility/`; the package-local generator reproduces their constraints. Composer selected Testbench 8.38.0 instead of the newer release requiring Laravel 10.50.3.

| Environment | Laravel | Testbench | PHPUnit | HttpFoundation | Guzzle | Firebase JWT | Full suite |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Laravel 10 / HRM target | 10.50.2 | 8.38.0 | 10.5.66 | 6.4.47 | 7.13.1 | 7.2.1 | 194 tests / 1,710 assertions |
| Laravel 11 | 11.57.0 | 9.18.0 | 11.5.57 | 7.4.20 | 7.15.5 | 7.2.1 | 194 tests / 1,710 assertions |
| Laravel 12 independent resolution | 12.69.3 | 10.12.0 | 11.5.57 | 7.4.20 | 7.15.5 | 7.2.1 | 194 tests / 1,710 assertions |
| Declared transport/JWT floors | 10.50.2 | 8.38.0 | 10.5.66 | 6.4.47 | 7.8.2 | 7.1.0 | 194 tests / 1,710 assertions |
| Restored checked-in Laravel 12 baseline | 12.69.3 | 10.12.0 | 11.5.56 | 7.4.20 | 7.15.5 | 7.2.1 | 194 tests / 1,710 assertions |

All four full suites passed with zero errors, failures or skipped tests. PHPStan level 5 passed in all four environments. Pint passed for Laravel 11/12 and the floor environment. Matrix manifest validation passed, using `--no-check-all` only because exact historical pins in test fixtures are intentional; the package manifest is validated with the normal strict command. No tests were added, removed or weakened; counts remain unchanged. Full suites retain file/SQLite independent-process races, crashed-claimant protection, provenance and clone rejection, strict callback parsing/scrubbing, signature/claim validation, optional matching-sub UserInfo, provider/session/middleware integration and argument-enabled safe-error trace tests.

The original Laravel 12 vendor environment was restored from the checked-in lock afterward. Final `composer validate --strict --no-check-publish`, PHPStan level 5, full PHPUnit (194 tests / 1,710 assertions, zero failures/errors/skips), Pint and `git diff --check` all passed. Package versions in this final lock were compared with the pre-change Git baseline and were identical.

### Synthetic HRM solver evidence and advisories

A separate no-install consumer fixture required `doxa/laravel-sdk` through a path repository, Laravel 10.50.2, PHP 8.2.12, Sanctum 3.3.3, Guzzle 7.13.1 and HttpFoundation ^6.4. Composer successfully retained those exact Laravel/Sanctum/Guzzle versions and resolved HttpFoundation 6.4.47 and Firebase JWT 7.2.1. No Illuminate 12 components or framework upgrade were required. This is the supplied HRM dependency subset, not its real complete manifest/lockfile, which was not available for verification.

Default Composer advisory blocking rejected the historical Laravel 10/11 targets and older Guzzle versions. Compatibility resolutions used an explicit command-only `--no-security-blocking` exception. Audit reporting remained enabled; no advisory-ignore or blocking-policy change was written to the package manifest. The synthetic HRM fixture reported 10 advisories affecting Laravel and Guzzle; Laravel 11 reported four Laravel advisories; the floor profile reported 13 advisories affecting Laravel and Guzzle. The independently resolved Laravel 12 environment reported no advisories.

For the exact HRM fixture, Laravel advisory IDs were PKSA-d5tc-s1qs-h781, PKSA-m5cs-t1y6-qpcs, PKSA-3r5d-mb8f-1qw9 and PKSA-mdq4-51ck-6kdq. Guzzle IDs were PKSA-gcrk-3vtt-1r14, PKSA-cnw1-2ytm-cgr8, PKSA-fy2t-3c5f-827y, PKSA-qxvb-2bpp-dnk6, PKSA-bbs6-q5q9-f3t4 and PKSA-pwsk-hy21-4gby. These cover framework debug/signed-URL/email validation and HTTP host/cookie/redirect/proxy handling. Passing SDK contract tests does not establish that these historical host dependencies are vulnerability-free. A Composer installation retaining them can require a separately reviewed advisory-policy decision; this task does not make that decision for HRM or change its dependencies.

### Remaining deployment verification

The real HRM lockfile and application integration, live IAM, Redis/PostgreSQL concurrency, PHP runtimes other than 8.2.12, and every historical framework minor were not tested. Symfony 6.4 coverage uses a patched 6.4 release, not the historical 6.4.0 patch. The lower-bound job tests the requested Guzzle/JWT floors without forcing every transitive dependency to its historical minimum. The package-local matrix command is CI-ready; no repository-root CI workflow was changed under the package-only authorization. Profiles share vendor and must run sequentially in one checkout, or in independent CI checkouts. Temporary archive-write denials during downloads were recovered by direct Composer install retries.

The original Laravel 12 milestone record below is retained as historical evidence; the compatibility runs above extend it.

Verified 2026-10-05 against the unchanged Doxa Universal SDK Contract v1.0 in `../../docs/doxa-sdk-contract.md`.

## Implementation

Independent Composer package, PHP 8.2+, Laravel 12+, namespace `Doxa\Laravel`. Dedicated configuration, discovery, HTTP, authorization, transaction, exchange, JWKS, validation, identity and UserInfo services are orchestrated by `DoxaClient`. The service provider publishes configuration and registers services without routes, guards, local account provisioning or session login.

Firebase PHP-JWT is the sole JWT library: it supplies RSA/JWK parsing and signature verification; the SDK enforces the OIDC validation policy. Guzzle supplies isolated HTTP transport. Runtime dependencies are declared in the package manifest, independently of IAM. Composer's lock refresh reported no vulnerability advisories.

## Commands and results

Run from this package directory on PHP 8.2.12, Laravel 12.69.3 and PHPUnit 11.5.56:

| Command | Result |
| --- | --- |
| `php vendor/bin/phpunit` | Passed: 194 tests, 1,710 assertions; unit, integration and concurrency suites |
| `php vendor/bin/pint --test` | Passed |
| `php vendor/bin/phpstan analyse --no-progress` | Passed, level 5, no errors |
| `composer validate --strict` | Passed |
| Focused remediation/protocol/callback/race tests | Passed: 170 tests, 1,671 assertions |
| Dedicated concurrency tests | Passed: 5 tests, 76 assertions |
| Laravel `package:discover` | Passed: isolated provider test, 1 test, 6 assertions; exit 0 |

Integration coverage includes actual Laravel web routes/session cookies, provider bindings, configuration publishing, guest preservation, callback response headers and an isolated transport that does not emit Laravel HTTP logging events. Provider responses are test fixtures; no live IAM deployment was exercised.

## Independent-process concurrency

Three simultaneous-callback and three direct-exchange rounds per storage backend were executed against shared file storage and shared SQLite database storage. Twelve rounds launched twenty-four independent PHP workers, synchronized before callback processing. Every round produced exactly one successful callback and exactly one token exchange; the losing worker failed closed. Pending encrypted secrets were absent afterward.

Two additional independent workers exercised a claimant that exits without completing the exchange, followed by a replay attempt. The attempt remained terminal and the second worker made zero token requests. These are process races, not sequential replay simulations. Redis and PostgreSQL deployments were not exercised.

## Milestone 1 security remediation

Store creation accepts only live transaction objects generated by `Authorization`, which checks provider metadata against trusted discovery before generating state, nonce and PKCE. Caller-created objects cannot acquire provenance by being passed through store creation. Public exchange and identity validation now require the exact live transaction object issued by the configured store's atomic claim. Constructed/cloned, expired, terminal and foreign-context transactions fail closed. An additional durable atomic exchange marker prevents repeat exchange, including uncertain transport outcomes. Authorization receives trusted discovery, and exchange/validation constructors receive the shared transaction store; its interface adds `assertClaimed()` and `consume()` without changing the application-facing API.

Identity attribute extraction receives only allowlisted optional claims, with sensitive parameters redacted from traces. Separate PHP processes explicitly enable `zend.exception_ignore_args=0` and recursively inspect actual captured trace arguments, private/protected object properties and closure captures for malformed ID-token and UserInfo claims and UserInfo subject/media-type failures, without relying on `__debugInfo()`. PHP SensitiveParameterValue wrappers are treated as redacted parameters. Malformed UserInfo attributes produce `UserInfoException / userinfo_failure`; malformed ID-token attributes retain `IdTokenValidationException / identity_extraction_failure`.

Focused remediation/protocol/callback/service-provider/race verification passed: 170 tests, 1,671 assertions. Coverage includes valid original transactions remaining usable after forged/cloned/wrong-context rejection, direct successful and uncertain exchange replay, identity/UserInfo allowlist minimization, and recursive public error trace checks with private-property/closure negative controls. Service-provider tests use isolated file caches so previous fixture keys cannot interfere.

The trace audit found additional unredacted configuration/metadata and failed transaction-store constructor arguments; targeted SensitiveParameter annotations close those diagnostic leaks. PublicErrorTraceTest now passes 24 tests, 1,244 assertions: 23 public failure paths plus a normal PHP wrapper-behavior regression. It covers ID-token audience/expiry/nonce/signature validation, authentication/extraction, UserInfo, transaction operations, exchange/replay, authorization, metadata, transport, callback parsing, malformed JWKS and invalid configuration. Error categories and messages are asserted unchanged; no lower-level causes are retained.

### Finding 2 targeted hardening

Identity attribute extraction, JWKS parsing and bounded configuration validation now return failure results before callers construct their existing typed exceptions. Consequently, their optional-claims arrays, provider payloads and malformed configuration values are absent from outward exception argument graphs. Credential-bearing invalid URLs have targeted sensitive-parameter protection. All public signatures and error categories remain unchanged; only private helper result types changed.

Recursive tests distinguish legitimate protected public inputs from unnecessary internal snapshots: they verify the payload-bearing private helper frames are absent, wrappers appear only at expected public boundaries, and no unprotected sensitive values occur in the remaining argument graph. Negative controls detect values in private object properties and closure captures without using debug rendering. PHP SensitiveParameterValue::getValue() retains its normal behavior, as intended; memory erasure is not the SDK's claim. Under the clarified requirement, Finding 2 is FIXED and no trace-reachability blocker remains.

Creation provenance uses PHP WeakMap identity only before storing a transaction. Independent workers claim protected persisted records through the existing store and receive process-local claimed-object provenance. This is Laravel/PHP-specific implementation, not a new universal contract or second transaction subsystem. A future Node/TypeScript SDK implements equivalent guarantees independently. Application-facing DoxaClient/identity APIs remain unchanged; internal constructors and the TransactionStore interface have the previously documented security-required changes.

## Security and contract coverage

| Contract requirements | Verified implementation and coverage |
| --- | --- |
| AC01 | Exact issuer, typed metadata, discovered HTTPS endpoints, capability validation; no endpoint overrides or fallback |
| AC02 | Explicit openid scopes; independent 256-bit state, nonce and verifier; mandatory S256 for public/confidential clients |
| AC03–AC06 | Encrypted server-side records; browser binding; ten-minute maximum; context/substitution checks; atomic durable claim; duplicate raw parameters rejected; terminal failures; exact exchange values; no retry |
| AC07–AC10 | Cryptographic RS256 verification, trusted RSA JWKS, algorithm/key confusion rejection, exact issuer, audience/azp, exp/iat/nbf, nonce and subject validation |
| AC11–AC12 | Immutable allowlisted identity, preserved issuer/subject, typed optional profile/email fields including false; no internal IDs or authorization claims |
| AC13 | UserInfo optional; bearer header only; openid provenance and matching subject; enrichment never changes identity key |
| AC14–AC15 | Finite discovery/JWKS caching; provider cache bounds; rate-limited unknown-kid refresh; public none and confidential Basic/post, without downgrade |
| AC16–AC17 | Safe typed exceptions without raw responses/causes; sensitive debug/serialization protection; bounded TLS transport; scrubbed callback query; no identity on failed validation |
| AC18–AC20 | No provisioning, roles, account activation, email linking, local login or access-token session conversion; no refresh-based identity or cross-application revocation promise |

Negative tests cover malformed/missing token responses, signature tampering, unsupported/symmetric algorithms, unusable/duplicate RSA keys, wrong issuer/audience/azp, invalid time claims, wrong/missing nonce/subject, access-token substitution, state/browser mismatch, transaction corruption/expiry/replay, ambiguous callbacks, provider denial and uncertain exchange. Tokens and client secrets are not returned in identity or generated authorization URLs. Unneeded refresh tokens are discarded.

## Deployment requirements and limitations

- HTTPS is required even in development; no insecure transport switch exists. Time validation uses zero clock skew, a stricter policy than the contract's maximum 60 seconds. Hosts must synchronize clocks.
- A started server-side Laravel session with Secure and HttpOnly cookies and SameSite Lax or None is required. Changing the session identifier during an outstanding attempt requires a new login.
- Transactions require file, database or Redis cache storage with reliable atomic add. Multi-host deployments must use shared storage; local file cache alone cannot coordinate separate hosts. Array, cookie and unsupported cache stores fail closed.
- Atomic claim markers persist through processing and expire after the bounded replay window. Cache outages or claim uncertainty fail closed. A worker crash can require a new login; it never permits code retry.
- Discovery/JWKS fetches are bounded and rate limited. Concurrent cold-cache callers may fail closed instead of waiting for another caller's fetch. IAM currently publishes one key; validation of old tokens after replacement is not guaranteed.
- Optional UserInfo failure fails the login rather than returning silently altered identity. Tokens remain transient server-side values; this milestone provides no token-management API.
- The opt-in `SecureCallbackResponse` middleware supplies no-store/no-referrer responses. Hosts must apply it and keep callback credentials out of their own exception reporters, access logs, tracing and reverse-proxy logs. SDK request scrubbing cannot control infrastructure logging before invocation.
- Live IAM reachability, production Redis/PostgreSQL behavior, other PHP/Laravel releases and production client registration remain deployment verification work. Central assignment changes do not terminate a host application's existing session.

## Deferred and scope

HRM integration, local identity mapping/session creation, provisioning, authorization, IAM administration, logout federation, refresh management, revocation, invitations, MFA, signing-key rotation and other language SDKs are intentionally deferred. No contract change was needed. Package development leaves IAM behavior and existing documents unchanged. No commit or push was performed.
