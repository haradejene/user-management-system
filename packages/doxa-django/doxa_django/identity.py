"""Normalized Doxa Identity representation."""

from dataclasses import dataclass, field
from typing import Any, Mapping


@dataclass(frozen=True)
class DoxaIdentity:
    """Normalized immutable identity returned after OIDC validation and access verification."""

    issuer: str
    subject: str
    email: str = ""
    name: str = ""
    email_verified: bool = False
    claims: Mapping[str, Any] = field(default_factory=dict)
