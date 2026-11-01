"""Settings helper for Django integration."""

from typing import List, Optional

try:
    from django.conf import settings  # type: ignore[import-untyped,import-not-found]
    HAS_DJANGO = True
except ImportError:
    settings = None
    HAS_DJANGO = False


def get_setting(name: str, default: Optional[str] = None) -> Optional[str]:
    if HAS_DJANGO and hasattr(settings, name):
        return getattr(settings, name)
    return default


def get_doxa_client():
    from doxa_django.client import DoxaClient

    return DoxaClient(
        issuer=get_setting("DOXA_ISSUER", ""),
        client_id=get_setting("DOXA_CLIENT_ID", ""),
        redirect_uri=get_setting("DOXA_REDIRECT_URI", ""),
        client_secret=get_setting("DOXA_CLIENT_SECRET"),
        scopes=get_setting("DOXA_SCOPES", ["openid", "profile", "email"]),
    )
