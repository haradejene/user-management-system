# doxa-django

Official Django authentication package & SDK for **Doxa Central IAM** (OpenID Connect / OAuth 2.0).

Strictly compliant with the **Doxa Universal SDK Contract v1.3**.

---

## Features

- **Mandatory S256 PKCE**: Automatic cryptographic `code_verifier` and URL-safe SHA-256 `code_challenge` derivation.
- **OIDC Discovery & JWKS Caching**: Automatically discovers authorization endpoints and caches public RSA keys (300s TTL).
- **Cryptographic ID Token Validation**: Verifies RS256 JWT signature using standard RSA / PyJWT or standard cryptography.
- **Authoritative Application Access Check**: Enforces fail-closed validation at `/oauth/application-access` (`{"effective": true}`) before returning identity.
- **Strict Separation of Concerns**: Implements `AccountResolver` so Central IAM handles authentication and authoritative app access, while Django retains full control over local `User` models, permissions, groups, and sessions.
- **Seamless Django Integration**: Provides drop-in views, URL routing, and a Django authentication backend (`doxa_django.backend.DoxaAuthBackend`).

---

## Installation

```bash
pip install doxa-django
```

Requires **Python 3.9+** and **Django 4.0+**.

---

## Quick Start

### 1. Configure `settings.py`

Add `doxa_django` to your `INSTALLED_APPS` and configure the authentication backend:

```python
INSTALLED_APPS = [
    ...,
    'django.contrib.auth',
    'django.contrib.sessions',
    'doxa_django',
]

AUTHENTICATION_BACKENDS = [
    'doxa_django.backend.DoxaAuthBackend',
    'django.contrib.auth.backends.ModelBackend',
]

DOXA_AUTH = {
    'ISSUER': 'https://iam.example.com',
    'CLIENT_ID': '01a113a7-739f-7071-bc0a-52006dd27e74',
    'CLIENT_SECRET': 'your-client-secret',  # Optional for public clients
    'REDIRECT_URI': 'https://myapp.com/auth/doxa/callback/',
    'SCOPES': ['openid', 'profile', 'email'],
    'LOGIN_REDIRECT_URL': '/dashboard/',
    'ACCOUNT_RESOLVER': 'myapp.resolvers.MyAccountResolver',
}
```

### 2. Implement `AccountResolver`

Define how verified Central IAM identities map to your local Django user accounts:

```python
# myapp/resolvers.py
from django.contrib.auth import get_user_model
from doxa_django.resolvers import AccountResolver
from doxa_django.identity import DoxaIdentity

User = get_user_model()

class MyAccountResolver(AccountResolver):
    def resolve(self, identity: DoxaIdentity):
        # 1. Look up existing local user by immutable subject ID or username
        user, created = User.objects.get_or_create(
            username=f"doxa_{identity.subject}",
            defaults={
                'email': identity.email,
                'first_name': identity.name.split(' ')[0] if identity.name else '',
                'last_name': ' '.join(identity.name.split(' ')[1:]) if identity.name else '',
            }
        )
        return user
```

### 3. Add URLs

Include Doxa auth routes in your root `urls.py`:

```python
# myproject/urls.py
from django.urls import path, include

urlpatterns = [
    ...,
    path('auth/doxa/', include('doxa_django.urls', namespace='doxa')),
]
```

This registers:
- `{% url 'doxa:login' %}` (`/auth/doxa/login/`): Redirects to Central IAM login with S256 PKCE.
- `{% url 'doxa:callback' %}` (`/auth/doxa/callback/`): Validates code, tokens, application access, and logs the user in.

---

## Standalone Client Usage

You can also use `DoxaClient` directly in any Python web framework (FastAPI, Flask, Starlette):

```python
from doxa_django.client import DoxaClient

client = DoxaClient(
    issuer='https://iam.example.com',
    client_id='01a113a7-739f-...',
    redirect_uri='https://myapp.com/auth/callback/',
)

# 1. Begin login
tx = client.begin_login()
# Store tx.state, tx.nonce, tx.code_verifier in session
# Redirect user to tx.url

# 2. Callback
identity = client.handle_authorized_callback(
    query_params=request.GET.dict(),
    expected_state=session['state'],
    expected_nonce=session['nonce'],
    code_verifier=session['code_verifier'],
)

print(f"Verified user: {identity.subject} ({identity.email})")
```

---

## License

MIT
