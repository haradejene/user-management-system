# Central IAM

Phase 1 of the company's centralized user-management and identity system.

## Applications

- `backend`: Laravel JSON API
- `frontend`: Next.js App Router administrative interface
- PostgreSQL: primary database

OAuth 2.0, Laravel Passport, and OpenID Connect are intentionally deferred to a later phase.

## Local prerequisites

- PHP 8.2 or newer
- Composer
- Node.js and npm
- PostgreSQL

## Initial setup

1. Create a PostgreSQL database named `user_management` owned by a dedicated application role.
2. Copy `backend/.env.example` to `backend/.env` and set the database credentials.
3. Run `php artisan key:generate` from `backend` if `APP_KEY` is empty.
4. Copy `frontend/.env.example` to `frontend/.env.local`.
5. Run `php artisan migrate` from `backend` after the Phase 1 migrations are added.

## Development servers

From `backend`:

```powershell
php artisan serve
```

From `frontend`:

```powershell
npm run dev
```

The frontend runs at `http://localhost:3000` and the API at `http://localhost:8000`.

## Initial audit logging

Run `php artisan migrate` from `backend` to create `audit_logs`.
The backend records registration, successful and failed login, logout, user/company/application
creation and updates, status changes, company membership changes, and application access grants/revocations.
Records include the actor (nullable), action, subject public UUID, timestamp, IP address,
user agent, and allowlisted metadata. Password values, tokens, and request bodies are excluded;
updates record changed field names rather than field values. Unchanged status and repeated
access revocation do not produce events.

Business mutations and their audit inserts share a database transaction: an audit write failure
rolls back the mutation. Logs survive actor deletion. This initial backend implementation has
no audit browsing or mutation API and does not audit direct database writes, factories, or seeders.
Run `php artisan test` from `backend` to verify it.

Services dispatch `IamActivityOccurred` at the business operation boundary. Laravel automatically
discovers `RecordIamActivity`, which delegates persistence and request-context capture to
`AuditService`. The listener runs synchronously inside the transaction, without a queue or
after-commit dispatch. Authentication events are emitted explicitly after IAM account checks
so an inactive account cannot generate a successful-login audit event. Controllers contain no
audit logic. The shared activity event keeps the initial implementation small while providing
one place to subscribe to these operations.

Audit table design:

- `id`: internal sequence and a tie-breaker for events with the same timestamp.
- `actor_id`: nullable foreign key for the live user relationship; deletion sets it to null.
- `actor_public_id`: historical actor UUID, retained independently of the user row.
- `action`: stable event name; status transitions include previous and new status in metadata.
- `subject_type` and `subject_id`: resource type and historical public UUID, without a cascading foreign key.
- `metadata`: allowlisted JSON for changed field names, status, related company/application UUIDs, or failure reason.
- `ip_address` and `user_agent`: request context; the user agent is limited to 1,024 characters by the writer.
- `created_at`: timezone-aware event timestamp. There is no update timestamp or soft deletion column.

Composite indexes support actor, action, and subject history ordered by `created_at` and `id`.
Unknown actors and subjects may be null, including failed login attempts. The follow-up migration
backfills actor UUIDs from existing users; identities already lost through hard deletion cannot
be recovered. Logs have no automatic expiry or application edit/delete endpoint; database-level
immutability and retention enforcement are not part of this initial implementation.
