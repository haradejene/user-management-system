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
