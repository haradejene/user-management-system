# IAM account selection and cancellation

IAM still has one authenticated identity per browser session. Saved accounts are
not additional authenticated sessions, application grants, or HRM mappings.

## Cancellation diagnosis

The reported wording comes from Passport's `InvalidAuthTokenException`, not
Laravel's CSRF exception. The consent form submits `POST /oauth/authorize` with
`_method=DELETE`, `_token` (CSRF), and `auth_token` (one-use consent token).
The separate picker submits `POST /oauth/select-account` using `choice_token`.

A real rendered consent form cancels correctly even after another tab opens
authorization. Replaying a consumed consent token reproduces Passport's 403.
The original browser's historical cookie/page state was not available, so this
does not establish whether that particular page was consumed, expired, or lost
its pending session record. Missing/expired pending records and a different
active token explain the same Passport check. The session cookie remains the
existing HttpOnly `doxa_iam_session`; session/certificate configuration is unchanged.

CSRF still runs before cancellation. Valid consent cancellation returns
`access_denied` with the original state to the registered callback. A stale
cancel page with valid CSRF now returns a local HTTP 410 explanation, without
reconstructing a callback from stale browser inputs or consuming other pending
requests. Invalid CSRF still returns 419. Approval validation is unchanged.

## Separate actions

- **Cancel account selection:** consume the session-bound choice and return
  `access_denied` with its original state. No account is authenticated or approved.
- **Cancel consent:** decline the validated authorization using Passport; no code
  is issued. The current IAM session stays signed in.
- **Choose an account from consent:** consume that consent page and transfer its
  exact validated query to a new server-side choice. Explicit `prompt=consent`
  stays intact, as do nonce, state, redirect URI, scopes and S256 challenge.
- **Use another account:** show an empty email/password form. A remembered hint
  pre-fills only email. Failed credentials cannot resume authorization or change
  identity; successful credentials regenerate the session and bind pending choices
  to the newly authenticated identity. Effective access is rechecked on resume.

The frontend receives only a random, session-bound, ten-minute choice handle and
an application label. All authorization parameters remain server-side. JSON
choice submissions initialize IAM CSRF first, include cookies and the XSRF token,
and return a destination chosen by the server. Authorization resumes at the
configured public issuer. CORS was extended only for the two choice endpoints,
using the existing allowed frontend origin. Login sends no referrer.

## Remembered labels

Only IAM's frontend origin reads `localStorage["doxa.remembered-accounts.v1"]`.
Nothing is shared through an iframe or a server-side user directory. Saving is
explicitly opt-in during sign-in/registration, or through the signed-in card.
Each entry has only `email`, `name`, and `expiresAt`; at most five entries live
for up to 30 days. Passwords, session cookies, tokens, authorization codes,
state, nonce and choice handles are never roster fields. Reading validates the
shape and strips unknown fields. Storage failures do not bypass authentication.

Remembered names/emails are personal data visible to anyone using the same
browser profile. The UI warns against saving on shared devices and supports
removal. Removal forgets the label, not the authenticated session. Accounts
used before this feature do not appear automatically: sign in and opt in, or
save the current authenticated account. A saved label is always untrusted;
editing storage cannot authenticate or grant access to anyone.

## Verification

- Backend: 86 tests passed (660 assertions), covering account choices, real
  credential/session transitions, authorization, browser sessions and authentication.
  Dedicated tests enforce the real CSRF middleware, including browser POST/DELETE
  method override, rejection without CSRF, replay, and terminal cancellation.
- Frontend: 116 unit tests passed, including malformed/tampered storage, expiry,
  bounds, removal, blocked storage and password-required hints.
- Chrome: six UI-boundary tests passed. These use mocked identity endpoints;
  they do not use live accounts, write grants, or create HRM sessions.
- TypeScript and ESLint passed. SDK code is unchanged in this change; 45 SDK
  authorization-option/login regression tests passed (180 assertions), including
  denial being terminal without returning an identity. Laravel version matrices
  were not rerun.

Backend tests use SQLite's disposable test database and test-process-only
`SANCTUM_STATEFUL_DOMAINS=localhost:3000` / `FRONTEND_URL=http://localhost:3000`,
matching existing fixtures. No local environment file is changed.

The focused Chrome suite uses the running IAM frontend on port 3001:

```powershell
npx.cmd playwright test --config=playwright.account-selection.config.ts
```

No real credential-based HRM end-to-end sign-in or new HRM session is claimed.
Host-side identity linking, roles and account provisioning remain outside IAM
selection and were not modified.
