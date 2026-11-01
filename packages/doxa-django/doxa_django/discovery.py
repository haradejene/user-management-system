"""OIDC discovery metadata and JWKS key retriever."""

import json
import time
import urllib.request
from typing import Any, Dict
from doxa_django.exceptions import ConfigurationException


class OidcDiscovery:
    def __init__(self, issuer: str, cache_ttl_seconds: int = 300):
        if not issuer:
            raise ConfigurationException("Issuer URL is required")
        self.issuer = issuer.rstrip("/")
        self.cache_ttl = cache_ttl_seconds
        self._metadata: Dict[str, Any] | None = None
        self._metadata_expires_at: float = 0
        self._jwks: Dict[str, Any] | None = None
        self._jwks_expires_at: float = 0

    def get_metadata(self) -> Dict[str, Any]:
        now = time.time()
        if self._metadata and now < self._metadata_expires_at:
            return self._metadata

        url = f"{self.issuer}/.well-known/openid-configuration"
        try:
            req = urllib.request.Request(url, headers={"Accept": "application/json"})
            with urllib.request.urlopen(req, timeout=10) as resp:
                if resp.status != 200:
                    raise ConfigurationException(f"Discovery endpoint returned HTTP {resp.status}")
                data = json.loads(resp.read().decode("utf-8"))
        except Exception as e:
            if isinstance(e, ConfigurationException):
                raise
            raise ConfigurationException(f"Failed to reach discovery endpoint: {e}") from e

        if not data.get("issuer") or not data.get("authorization_endpoint") or not data.get("token_endpoint") or not data.get("jwks_uri"):
            raise ConfigurationException("Discovery response missing required endpoints")

        self._metadata = data
        self._metadata_expires_at = now + self.cache_ttl
        return self._metadata

    def get_jwks(self) -> Dict[str, Any]:
        now = time.time()
        if self._jwks and now < self._jwks_expires_at:
            return self._jwks

        metadata = self.get_metadata()
        jwks_uri = metadata["jwks_uri"]
        try:
            req = urllib.request.Request(jwks_uri, headers={"Accept": "application/json"})
            with urllib.request.urlopen(req, timeout=10) as resp:
                if resp.status != 200:
                    raise ConfigurationException(f"JWKS endpoint returned HTTP {resp.status}")
                data = json.loads(resp.read().decode("utf-8"))
        except Exception as e:
            if isinstance(e, ConfigurationException):
                raise
            raise ConfigurationException(f"Failed to retrieve JWKS: {e}") from e

        if "keys" not in data or not isinstance(data["keys"], list):
            raise ConfigurationException("JWKS response missing keys list")

        self._jwks = data
        self._jwks_expires_at = now + self.cache_ttl
        return self._jwks
