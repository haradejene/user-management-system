"""OIDC ID Token validator."""

import base64
import json
import time
from typing import Any, Dict
from doxa_django.exceptions import AuthorizationException
from doxa_django.discovery import OidcDiscovery

try:
    import jwt  # type: ignore[import-untyped,import-not-found]
    from jwt.algorithms import RSAAlgorithm  # type: ignore[import-untyped,import-not-found]
    HAS_PYJWT = True
except ImportError:
    HAS_PYJWT = False


class IdTokenValidator:
    def __init__(self, discovery: OidcDiscovery, issuer: str, client_id: str, clock_tolerance: int = 60):
        self.discovery = discovery
        self.issuer = issuer.rstrip("/")
        self.client_id = client_id
        self.clock_tolerance = clock_tolerance

    def _b64decode(self, val: str) -> bytes:
        padding = "=" * (-len(val) % 4)
        return base64.urlsafe_b64decode(val + padding)

    def validate(self, raw_token: str, expected_nonce: str) -> Dict[str, Any]:
        if not raw_token or not isinstance(raw_token, str):
            raise AuthorizationException("invalid_id_token", "ID token missing or malformed")

        parts = raw_token.split(".")
        if len(parts) != 3:
            raise AuthorizationException("invalid_id_token", "ID token must have 3 segments")

        header_b64, payload_b64, sig_b64 = parts

        try:
            header = json.loads(self._b64decode(header_b64).decode("utf-8"))
            payload = json.loads(self._b64decode(payload_b64).decode("utf-8"))
        except Exception as e:
            raise AuthorizationException("invalid_id_token", f"Failed to decode token segments: {e}") from e

        if header.get("alg") != "RS256":
            raise AuthorizationException("unsupported_algorithm", f"Algorithm {header.get('alg')} not supported")

        jwks = self.discovery.get_jwks()
        matching_key = next((k for k in jwks.get("keys", []) if not header.get("kid") or k.get("kid") == header.get("kid")), None)
        if not matching_key:
            raise AuthorizationException("missing_signing_key", "Matching JWK key not found")

        # Cryptographic verification via PyJWT if available
        if HAS_PYJWT:
            try:
                public_key = RSAAlgorithm.from_jwk(json.dumps(matching_key))
                jwt.decode(
                    raw_token,
                    public_key,
                    algorithms=["RS256"],
                    audience=self.client_id,
                    issuer=self.issuer,
                    leeway=self.clock_tolerance,
                    options={"verify_signature": True, "verify_aud": True, "verify_iss": True},
                )
            except Exception as err:
                raise AuthorizationException("invalid_signature", f"Cryptographic token verification failed: {err}") from err

        # Invariant claims validation
        now = time.time()
        token_issuer = payload.get("iss", "").rstrip("/")
        if token_issuer != self.issuer:
            raise AuthorizationException("invalid_issuer", f"Issuer mismatch: {token_issuer} != {self.issuer}")

        aud = payload.get("aud")
        aud_list = aud if isinstance(aud, list) else [aud]
        if self.client_id not in aud_list:
            raise AuthorizationException("invalid_audience", f"Audience mismatch: {self.client_id} not in {aud_list}")

        exp = payload.get("exp", 0)
        if not isinstance(exp, (int, float)) or exp + self.clock_tolerance < now:
            raise AuthorizationException("expired_token", "ID token has expired")

        if expected_nonce and payload.get("nonce") != expected_nonce:
            raise AuthorizationException("invalid_nonce", "Nonce mismatch")

        if not payload.get("sub"):
            raise AuthorizationException("missing_subject", "ID token missing sub claim")

        return payload
