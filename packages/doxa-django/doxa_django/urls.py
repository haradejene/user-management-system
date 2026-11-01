"""Django URLs routing for Doxa OIDC endpoints."""

try:
    from django.urls import path  # type: ignore[import-untyped,import-not-found]
    from doxa_django.views import DoxaLoginView, DoxaCallbackView
    HAS_DJANGO = True
except ImportError:
    HAS_DJANGO = False

app_name = "doxa"

urlpatterns = [
    path("login/", DoxaLoginView.as_view(), name="login"),
    path("callback/", DoxaCallbackView.as_view(), name="callback"),
] if HAS_DJANGO else []
