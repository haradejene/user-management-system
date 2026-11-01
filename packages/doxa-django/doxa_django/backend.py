"""Django authentication backend for verified Doxa users."""

from typing import Any, Optional

try:
    from django.conf import settings  # type: ignore[import-untyped,import-not-found]
    if settings.configured:
        from django.contrib.auth.backends import BaseBackend  # type: ignore[import-untyped,import-not-found]
    else:
        class BaseBackend:
            def authenticate(self, request: Any = None, **kwargs: Any) -> Optional[Any]:
                return None
            def get_user(self, user_id: Any) -> Optional[Any]:
                return None
    HAS_DJANGO = True
except Exception:
    class BaseBackend:
        pass
    HAS_DJANGO = False


class DoxaAuthenticationBackend(BaseBackend):
    """Pass-through backend for users resolved by DoxaAccountResolver."""

    def authenticate(self, request: Any, user: Optional[Any] = None, **kwargs: Any) -> Optional[Any]:
        if user and getattr(user, "is_active", True):
            return user
        return None


    def get_user(self, user_id: Any) -> Optional[Any]:
        if not HAS_DJANGO:
            return None
        try:
            from django.contrib.auth import get_user_model  # type: ignore[import-untyped,import-not-found]
            User = get_user_model()
            return User.objects.get(pk=user_id)
        except Exception:
            return None


DoxaAuthBackend = DoxaAuthenticationBackend
