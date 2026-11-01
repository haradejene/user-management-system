"""Application access verification."""

import json
import urllib.request
from doxa_django.exceptions import ApplicationAccessException


class ApplicationAccessValidator:
    def __init__(self, issuer: str):
        self.issuer = issuer.rstrip("/")

    def verify(self, access_token: str) -> bool:
        if not access_token:
            raise ApplicationAccessException("missing_access_token", "Access token is required")

        url = f"{self.issuer}/oauth/application-access"
        req = urllib.request.Request(
            url,
            headers={
                "Accept": "application/json",
                "Authorization": f"Bearer {access_token}",
            },
            method="GET",
        )

        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                if resp.status != 200:
                    raise ApplicationAccessException("access_denied_or_insufficient_scope", f"HTTP {resp.status}")
                content_type = resp.headers.get("Content-Type", "")
                if "application/json" not in content_type:
                    raise ApplicationAccessException("access_denied_or_insufficient_scope", "Invalid content type")
                data = json.loads(resp.read().decode("utf-8"))
        except ApplicationAccessException:
            raise
        except Exception:
            raise ApplicationAccessException("transient_service_failure", "Failed to reach application access verification service")

        if isinstance(data, dict) and data.get("effective") is True:
            return True

        raise ApplicationAccessException("access_denied_or_insufficient_scope", "Application access is not effective")
