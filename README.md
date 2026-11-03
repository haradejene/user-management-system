# User Management System (Central IAM)

The **User Management System** serves as the organization's enterprise **Identity & Access Management (IAM)** platform and authoritative OpenID Connect (OIDC) / OAuth 2.0 Identity Provider (IdP). It provides unified authentication, user and multi-tenant company lifecycle administration, authoritative application access control, and self-service developer integration SDKs for all downstream client services.

---

## Architecture Overview

```
                                      +---------------------------------------------+
                                      |           User Management System            |
                                      |               (Central IAM)                 |
                                      |                                             |
                                      |   - OpenID Connect / OAuth 2.0 Identity IdP |
                                      |   - Mandatory S256 PKCE & RS256 Signing     |
                                      |   - Authoritative Application Access Gate   |
                                      |   - Inbound Developer Request Approval Flow |
                                      +----------------------+----------------------+
                                                             |
                        +------------------------------------+------------------------------------+
                        |                                                                         |
                        v                                                                         v
+-----------------------------------------------+                       +-----------------------------------------------+
|         Private Admin Dashboard               |                       |           Public Developer Portal             |
|          (http://localhost:3000)              |                       |     (http://localhost:3000/developers)        |
|                                               |                       |                                               |
|  - Users, Companies & Access Management       |                       |  - Download Official SDKs (No Login Needed)   |
|  - Applications & OAuth Client Registry       |                       |  - Interactive .env & AccountResolver Setup   |
|  - Inbound OAuth Request Review & Approval    |                       |  - Submit OAuth Application Client Request    |
+-----------------------------------------------+                       |  - Real-time Request Tracking by Reference    |
                                                                        +-----------------------------------------------+
                                                                                                  |
                                        +---------------------------------------------------------+
                                        | (Downloads / Integrates SDKs)
                                        v
                 +---------------------------------------------------------+
                 |              Downstream Client Applications             |
                 |         (HRM, CRM, ERP, Partner Portals, SPAs)          |
                 |                                                         |
                 |  - PHP / Laravel  (`doxa/oauth-client`)                 |
                 |  - TypeScript / Node.js (`@doxa/auth-node`)             |
                 |  - Python / Django (`doxa-django`)                      |
                 |  - 100% Local Authorization via AccountResolver         |
                 +---------------------------------------------------------+
```

### Identity & Access Boundary Design
- **Identity Provider (User Management System / Central IAM)**: Authoritatively authenticates user credentials, manages user lifecycles (`active`, `suspended`, `deactivated`), issues signed RS256 ID tokens with S256 PKCE enforcement, and evaluates whether a user holds authoritative access to an application via `GET /oauth/application-access`.
- **Downstream Applications**: Do not manage global passwords or independent user tables. Instead, they authenticate through the User Management System and own 100% of their local authorization (roles, permissions, organization tenancies, and local sessions) via the standard `AccountResolver` contract.

---

## Core Capabilities

### 1. User & Access Administration (Private Admin Console)
- **Users Management (`/users`)**: Search, filter, inspect profiles, invite users, update accounts, and manage lifecycle status (`active`, `suspended`, `deactivated`).
- **Companies Management (`/companies`)**: Multi-tenant company directory and member associations.
- **Applications Registry (`/applications`)**: Downstream applications directory with client configuration, audit history, and direct user access provisioning.
- **Application Access Management (`/application-access`)**: Authoritative assignment matrix granting users access to specific applications.
- **Inbound OAuth Application Requests (`/oauth-requests`)**: Dedicated review queue for requests submitted from the public developer portal. Administrators review details, company linkages, redirect URIs, and approve requests with 1-click automated Application and OAuth Client generation.

### 2. Developer Integration Portal (`/developers`)
A fully public interface accessible without an administrator account:
- **Official SDK Downloads**: Direct download of source packages (`.zip`) and package manager installation instructions for:
  - **Laravel** (`composer require doxa/oauth-client`)
  - **Node.js** (`npm install @doxa/auth-node`)
  - **Django** (`pip install doxa-django`)
- **Interactive `.env` Generator**: Pre-configures framework environment variables with active discovery endpoints and client credentials.
- **Request OAuth Application**: Self-service application request form for developers:
  - Application Name, Developer Contact, and Client Security Profile (Confidential Server vs. Public PKCE).
  - Company selection dropdown (loaded dynamically from registered companies in the User Management System) with an "Other" option for external/independent developers.
  - Strict redirect URI validation (disallowing wildcards and URI fragments).
  - Issues a unique **Reference Code** (e.g. `REQ-XXXXXXXX`).
- **Track Request Status**: Allows developers to look up approval progress using their reference code. When approved, displays their issued Client ID and Client Secret, and auto-populates them into the SDK `.env` generator.

### 3. OpenID Connect & Security Architecture
- **Discovery**: `/.well-known/openid-configuration` publication.
- **Cryptographic Keys**: `GET /oauth/jwks` public key rotation (cached with 300s TTL).
- **Mandatory PKCE**: Mandatory RFC 7636 S256 code challenge on authorization flows.
- **Fail-Closed App Access Check**: Downstream SDKs verify user access against `GET /oauth/application-access`. If access has been revoked in the User Management System, entry is denied immediately.
- **Account Selection & Re-authentication**: Seamless multi-account switching and session resumption.

---

## Monorepo Layout

```
user-management-system/
├── backend/                       # Laravel 12 JSON API & OIDC Provider
│   ├── app/
│   │   ├── Enums/                 # ApplicationStatus, MembershipStatus, UserStatus
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── Applications/  # Admin application, client & request controllers
│   │   │   │   ├── Auth/          # Login, Register, Logout, Me controllers
│   │   │   │   ├── Companies/     # Company & membership controllers
│   │   │   │   ├── Developers/    # Public SDK download & OAuth request controllers
│   │   │   │   ├── Oidc/          # Discovery, JWKS, UserInfo, ApplicationAccess
│   │   │   │   └── Users/         # User administration controllers
│   │   │   └── Requests/          # Form requests with strict validation rules
│   │   ├── Models/                # Eloquent models (User, Application, OAuthClient, etc.)
│   │   └── Services/              # Core business services (OAuthClient, Audit, etc.)
│   ├── config/                    # Framework configuration (cors, oidc, sanctum, etc.)
│   ├── database/migrations/       # Database schema migrations
│   └── tests/                     # PHPUnit Feature and Unit test suites
├── frontend/                      # Next.js 16 (App Router) & React 19 Frontend
│   ├── src/
│   │   ├── app/
│   │   │   ├── (admin)/           # Protected administration routes (Dashboard, Users, etc.)
│   │   │   ├── (auth)/            # Public authentication routes (Login, Register)
│   │   │   └── developers/        # Public developer portal & request tracking
│   │   ├── components/            # Reusable UI, admin forms, and developer portal
│   │   ├── services/              # API clients and domain services (Axios)
│   │   └── types/                 # TypeScript type declarations
│   └── tests/                     # Vitest unit & integration test suites
├── packages/                      # Official Client SDKs
│   ├── doxa-laravel/              # Laravel OAuth Client package
│   ├── doxa-node/                 # TypeScript/Node.js client package
│   └── doxa-django/               # Django client & authentication backend package
└── docs/                          # Architecture & design specifications
```

---

## Prerequisites

- **PHP**: 8.2 or newer with extensions: `pdo_pgsql`, `mbstring`, `openssl`, `zip`
- **Composer**: 2.x
- **Node.js**: 18.x or newer & **npm**
- **PostgreSQL**: 14 or newer

---

## Local Setup & Installation

### 1. Database Setup
Create a PostgreSQL database and user:

```sql
CREATE DATABASE user_management;
CREATE USER manage_user WITH PASSWORD 'usermanage12';
GRANT ALL PRIVILEGES ON DATABASE user_management TO manage_user;
```

### 2. Backend Setup

```powershell
cd backend

# Install PHP dependencies
composer install

# Environment configuration
cp .env.example .env
php artisan key:generate

# Execute database migrations
php artisan migrate
```

Verify your `backend/.env` file:
```ini
APP_NAME=UserManagementSystem
APP_ENV=local
APP_URL=http://localhost:8002
FRONTEND_URL=http://localhost:3000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=user_management
DB_USERNAME=manage_user
DB_PASSWORD=usermanage12

SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:3001,127.0.0.1:3000,127.0.0.1:3001
SESSION_COOKIE=doxa_iam_session
SESSION_SAME_SITE=lax
```

### 3. Frontend Setup

```powershell
cd ../frontend

# Install JavaScript dependencies
npm install

# Environment configuration
cp .env.example .env.local
```

Ensure `frontend/.env.local` targets your backend API:
```ini
NEXT_PUBLIC_API_URL=http://localhost:8002
```

---

## Running Development Servers

### 1. Start Backend API Server
Run from the `backend/` directory on port `8002`:

```powershell
cd backend
php artisan serve --host=127.0.0.1 --port=8002
```

### 2. Start Frontend Next.js Server
Run from the `frontend/` directory on port `3000`:

```powershell
cd frontend
npm run dev
```

### 3. Accessing the Application
- **Public Developer Portal**: `http://localhost:3000/developers` (Download SDKs, submit application requests, track status)
- **User Management Admin Console**: `http://localhost:3000/dashboard` (Requires system administrator login)
- **Backend API & Discovery**: `http://localhost:8002/.well-known/openid-configuration`

---

## Testing

### Backend PHPUnit Tests
Run the complete backend feature test suite using SQLite in-memory:

```powershell
cd backend
php vendor/phpunit/phpunit/phpunit
```

Run specific test suites:
```powershell
# OAuth Application Request & Approval tests
php vendor/phpunit/phpunit/phpunit tests/Feature/Developers/OAuthApplicationRequestTest.php

# SDK Distribution & Catalog tests
php vendor/phpunit/phpunit/phpunit tests/Feature/Developers/SdkDownloadTest.php
```

### Frontend Vitest Tests
Run the comprehensive React Testing Library test suite (124+ unit and integration tests):

```powershell
cd frontend
npm test
```

Run test suite in watch mode:
```powershell
npx vitest
```

---

## Developer Onboarding & OAuth Flow

```
1. Developer visits http://localhost:3000/developers (No account required)
2. Developer downloads SDK for Laravel, Node.js, or Django
3. Developer fills "Request OAuth Application" tab:
   - Selects Company from the user management system dropdown (or chooses "Other")
   - Enters App Name, Developer Contact, and Redirect URIs
   - Submits and receives Reference Code: REQ-XXXXXXXX
4. Admin opens http://localhost:3000/oauth-requests
5. Admin clicks "Review & Decide" -> "Approve Application & Issue Client"
   - Application is created and linked to the selected company
   - OAuthClient is generated with Client ID and Client Secret
6. Developer checks "Track Request Status" tab on /developers:
   - Enters Reference Code REQ-XXXXXXXX
   - Views approved Client ID & Client Secret
   - Clicks "Configure in SDK .env Generator" to populate local .env
```

---

## Official Client SDK Integration Examples

### Laravel (`doxa/oauth-client`)
```php
// app/Resolvers/HostUserResolver.php
namespace App\Resolvers;

use Doxa\OAuthClient\Contracts\AccountResolverInterface;
use Doxa\Identity\DoxaIdentity;
use App\Models\User;

class HostUserResolver implements AccountResolverInterface
{
    public function resolve(DoxaIdentity $identity): User
    {
        return User::firstOrCreate(
            ['doxa_sub' => $identity->subject],
            ['email' => $identity->email, 'name' => $identity->name, 'role' => 'member']
        );
    }
}
```

### Node.js / Express (`@doxa/auth-node`)
```typescript
// src/server.ts
import express from 'express';
import { DoxaClient, createExpressAuth } from '@doxa/auth-node';

const app = express();
const doxa = new DoxaClient({
  issuer: process.env.DOXA_ISSUER!,
  clientId: process.env.DOXA_CLIENT_ID!,
  redirectUri: process.env.DOXA_REDIRECT_URI!,
});

const auth = createExpressAuth(doxa);
app.get('/auth/doxa', auth.login());
app.get('/auth/doxa/callback', auth.callback(new HostUserResolver(), (user, req, res) => {
  req.session.userId = user.id;
  res.redirect('/dashboard');
}));
```

### Django (`doxa-django`)
```python
# settings.py
INSTALLED_APPS = [..., 'doxa_django']
AUTHENTICATION_BACKENDS = ['doxa_django.backend.DoxaAuthBackend', 'django.contrib.auth.backends.ModelBackend']

DOXA_AUTH = {
    'ISSUER': os.getenv('DOXA_ISSUER'),
    'CLIENT_ID': os.getenv('DOXA_CLIENT_ID'),
    'CLIENT_SECRET': os.getenv('DOXA_CLIENT_SECRET'),
    'REDIRECT_URI': os.getenv('DOXA_REDIRECT_URI'),
    'ACCOUNT_RESOLVER': 'myapp.resolvers.HostUserResolver',
}
```

---

## Audit Logging & Compliance

All sensitive business operations write immutable audit records within the database transaction:
- **Recorded Events**: User registration, login/logout, account state transitions (`suspended`, `deactivated`), company membership mutations, application access grant/revoke, and OAuth application request approval/rejection.
- **Attributes Captured**: Actor ID, subject ID, action identifier, timestamp, IP address, user agent, and allowlisted metadata keys. Credential data and request payloads are excluded.

---

## License

Proprietary — Internal user management & identity platform.
