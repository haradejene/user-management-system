# Milestone 1 verification

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
