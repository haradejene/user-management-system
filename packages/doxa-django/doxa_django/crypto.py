"""Cryptographic utilities for PKCE, state, and nonce generation."""

import base64
import hashlib
import secrets
from typing import Dict


def random_string(nbytes: int = 32) -> str:
    """Generate high-entropy URL-safe random string."""
    return secrets.token_urlsafe(nbytes)


def generate_pkce() -> Dict[str, str]:
    """Generate RFC 7636 compliant PKCE code_verifier and S256 code_challenge.
    
    Returns:
        dict with keys: verifier, challenge, method ('S256')
    """
    # 64 bytes results in 86 url-safe characters, well within 43-128 character RFC range.
    verifier = secrets.token_urlsafe(64)
    digest = hashlib.sha256(verifier.encode("ascii")).digest()
    challenge = base64.urlsafe_b64encode(digest).decode("ascii").rstrip("=")
    return {
        "verifier": verifier,
        "challenge": challenge,
        "method": "S256",
    }
