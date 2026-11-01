"""Core DoxaClient for Python & Django."""

import json
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional
from doxa_django.crypto import generate_pkce, random_string
from doxa_django.discovery import OidcDiscovery
from doxa_django.exceptions import (
    AuthorizationException,
    ConfigurationException,
    TokenException,
)
from doxa_django.identity import DoxaIdentity
from doxa_django.resolvers import AccountResolver
from doxa_django.validator import IdTokenValidator
from doxa_django.access import ApplicationAccessValidator


class DoxaClient:
    def __init__(
        self,
        issuer: str,
        client_id: str,
        redirect_uri: str,
        client_secret: Optional[str] = None,
        scopes: Optional[List[str]] = None,
    ):
        if not issuer or not client_id or not redirect_uri:
            raise ConfigurationException("issuer, client_id, and redirect_uri are required")

        self.issuer = issuer.rstrip("/")
        self.client_id = client_id
        self.redirect_uri = redirect_uri
        self.client_secret = client_secret
        self.scopes = scopes or ["openid", "profile", "email"]

        self.discovery = OidcDiscovery(self.issuer)
        self.validator = IdTokenValidator(self.discovery, self.issuer, self.client_id)
        self.access = ApplicationAccessValidator(self.issuer)

    def begin_login(self, options: Optional[Dict[str, str]] = None) -> Dict[str, str]:
        """Construct authorization redirect URL with state, nonce, and PKCE."""
        metadata = self.discovery.get_metadata()
        pkce = generate_pkce()
        state = random_string(32)
        nonce = random_string(32)

        params = {
            "response_type": "code",
            "client_id": self.client_id,
            "redirect_uri": self.redirect_uri,
            "scope": " ".join(self.scopes),
            "state": state,
            "nonce": nonce,
            "code_challenge": pkce["challenge"],
            "code_challenge_method": pkce["method"],
        }
        if options:
            if "prompt" in options:
                params["prompt"] = options["prompt"]
            if "login_hint" in options:
                params["login_hint"] = options["login_hint"]

        auth_url = f"{metadata['authorization_endpoint']}?{urllib.parse.urlencode(params)}"
        return {
            "url": auth_url,
            "state": state,
            "nonce": nonce,
            "code_verifier": pkce["verifier"],
        }

    def exchange_code(self, code: str, code_verifier: str) -> Dict[str, Any]:
        """Exchange authorization code for tokens."""
        if not code:
            raise TokenException("invalid_code", "Authorization code is required")
        if not code_verifier:
            raise TokenException("invalid_verifier", "PKCE code_verifier is required")

        metadata = self.discovery.get_metadata()
        token_endpoint = metadata["token_endpoint"]

        data = {
            "grant_type": "authorization_code",
            "client_id": self.client_id,
            "code": code,
            "redirect_uri": self.redirect_uri,
            "code_verifier": code_verifier,
        }
        if self.client_secret:
            data["client_secret"] = self.client_secret

        encoded_data = urllib.parse.urlencode(data).encode("utf-8")
        req = urllib.request.Request(
            token_endpoint,
            data=encoded_data,
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json",
            },
            method="POST",
        )

        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                body = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            try:
                err_body = json.loads(e.read().decode("utf-8"))
                cat = err_body.get("error", "token_exchange_failed")
                desc = err_body.get("error_description", str(e))
            except Exception:
                cat = "token_exchange_failed"
                desc = str(e)
            raise TokenException(cat, desc) from e
        except Exception as e:
            raise TokenException("network_error", f"Token exchange failed: {e}") from e

        if "access_token" not in body or "id_token" not in body:
            raise TokenException("invalid_response", "Token response missing required tokens")

        return body

    def handle_authorized_callback(
        self,
        callback_params: Dict[str, str],
        expected_transaction: Dict[str, str],
    ) -> DoxaIdentity:
        """Process callback, validate tokens, and verify authoritative application access."""
        if "error" in callback_params:
            raise AuthorizationException(
                callback_params["error"],
                callback_params.get("error_description", "Authorization was denied"),
            )

        code = callback_params.get("code")
        state = callback_params.get("state")
        if not code or not state:
            raise AuthorizationException("invalid_request", "Missing code or state parameter")

        expected_state = expected_transaction.get("state")
        if not expected_state or state != expected_state:
            raise AuthorizationException("state_mismatch", "Callback state does not match initiated transaction")

        # 1. Exchange code
        tokens = self.exchange_code(code, expected_transaction["code_verifier"])

        # 2. Validate ID Token
        claims = self.validator.validate(tokens["id_token"], expected_transaction["nonce"])

        # 3. Authoritative Application Access Check
        self.access.verify(tokens["access_token"])

        return DoxaIdentity(
            issuer=self.issuer,
            subject=claims["sub"],
            email=claims.get("email", ""),
            name=claims.get("name", ""),
            email_verified=bool(claims.get("email_verified", False)),
            claims=claims,
        )

    def resolve_account(
        self,
        callback_params: Dict[str, str],
        expected_transaction: Dict[str, str],
        resolver: AccountResolver,
    ) -> Any:
        """Validate OIDC callback, verify access, and resolve local host account."""
        if not isinstance(resolver, AccountResolver):
            raise ConfigurationException("resolver must be an instance of AccountResolver")

        identity = self.handle_authorized_callback(callback_params, expected_transaction)
        return resolver.resolve(identity)
