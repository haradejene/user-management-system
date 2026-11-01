# @doxa/auth-node

Official Node.js & TypeScript SDK for **Doxa Central IAM** (OpenID Connect / OAuth 2.0).

Designed strictly in compliance with the **Doxa Universal SDK Contract v1.3**.

---

## Features

- **Mandatory S256 PKCE**: Automatic cryptographic `code_verifier` and SHA-256 `code_challenge` derivation.
- **OIDC Discovery & JWKS Caching**: Automatically discovers authorization endpoints and retrieves/caches public RSA keys (300s TTL).
- **Cryptographic ID Token Validation**: Verifies RS256 JWT signature using native Node.js crypto (zero heavy external dependencies).
- **Authoritative Application Access Check**: Enforces a strict point-in-time check at `/oauth/application-access` (`{"effective": true}`) before returning identity.
- **Strict Separation of Concerns**: Implements `AccountResolver` so Central IAM proves identity, while your host application maintains 100% control over local users, roles, and sessions.
- **Adapters included**: Pre-built integration helpers for **Express** and **Next.js**.

---

## Installation

```bash
npm install @doxa/auth-node
```

Requires **Node.js 18.0.0** or higher.

---

## Quick Start (Express)

```typescript
import express from 'express';
import session from 'express-session';
import { DoxaClient, AccountResolver, DoxaIdentity, createExpressAuth } from '@doxa/auth-node';

const app = express();
app.use(session({ secret: 'your-secret', resave: false, saveUninitialized: false }));

const doxa = new DoxaClient({
  issuer: process.env.DOXA_ISSUER!,          // e.g. https://iam.example.com
  clientId: process.env.DOXA_CLIENT_ID!,      // e.g. 01a113a7-739f-7071-bc0a-52006dd27e74
  clientSecret: process.env.DOXA_CLIENT_SECRET, // (optional for public clients)
  redirectUri: process.env.DOXA_REDIRECT_URI!,// e.g. https://myapp.com/auth/doxa/callback
  scopes: ['openid', 'profile', 'email'],
});

// Implement AccountResolver: Map Doxa identity to your local database user
class UserResolver implements AccountResolver {
  async resolve(identity: DoxaIdentity) {
    // 1. Look up existing local user by immutable (issuer, subject)
    let user = await db.user.findUnique({ where: { doxaSub: identity.subject } });
    if (!user) {
      // 2. Safe host provisioning policy (deny email collisions, assign local role)
      user = await db.user.create({
        data: {
          doxaSub: identity.subject,
          email: identity.email,
          name: identity.name,
          role: 'MEMBER'
        }
      });
    }
    return user;
  }
}

const auth = createExpressAuth(doxa);

// 1. Initiate login
app.get('/auth/doxa', auth.login());

// 2. Handle OIDC callback
app.get('/auth/doxa/callback', auth.callback(new UserResolver(), (user, req, res) => {
  req.session.userId = user.id;
  res.redirect('/dashboard');
}));
```

---

## Standalone Usage (Fastify / Vanilla / Custom)

```typescript
import { DoxaClient } from '@doxa/auth-node';

const doxa = new DoxaClient({
  issuer: 'https://iam.example.com',
  clientId: '01a113a7-...',
  redirectUri: 'https://myapp.com/auth/callback',
});

// Step 1: Start login
const { url, state, nonce, codeVerifier } = await doxa.beginLogin();
// Save state, nonce, and codeVerifier in session
session.tx = { state, nonce, codeVerifier };
res.redirect(url);

// Step 2: Handle callback
const identity = await doxa.handleAuthorizedCallback(req.query, session.tx);
console.log('Verified Doxa Identity:', identity.subject, identity.email);
```

---

## License

MIT
