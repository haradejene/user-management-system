"""Django views for OIDC login and callback."""

from typing import Any
from doxa_django.conf import get_doxa_client, get_setting

try:
    from django.http import HttpRequest, HttpResponse, HttpResponseRedirect, JsonResponse  # type: ignore[import-untyped,import-not-found]
    from django.views import View  # type: ignore[import-untyped,import-not-found]
    from django.contrib.auth import login  # type: ignore[import-untyped,import-not-found]
    from django.utils.module_loading import import_string  # type: ignore[import-untyped,import-not-found]
    HAS_DJANGO = True
except ImportError:
    HAS_DJANGO = False
    View = object


class DoxaLoginView(View):
    """Initiates login with Central IAM and sets transaction session."""

    def get(self, request: Any, *args: Any, **kwargs: Any) -> Any:
        if not HAS_DJANGO:
            raise RuntimeError("Django is required to use DoxaLoginView")

        client = get_doxa_client()
        tx = client.begin_login()

        request.session["doxa_tx"] = {
            "state": tx["state"],
            "nonce": tx["nonce"],
            "code_verifier": tx["code_verifier"],
        }

        return HttpResponseRedirect(tx["url"])


class DoxaCallbackView(View):
    """Processes OIDC callback from Central IAM and establishes local session."""

    def get(self, request: Any, *args: Any, **kwargs: Any) -> Any:
        if not HAS_DJANGO:
            raise RuntimeError("Django is required to use DoxaCallbackView")

        tx = request.session.pop("doxa_tx", None)
        if not tx:
            return JsonResponse({"error": "invalid_transaction", "message": "Missing session transaction"}, status=400)

        resolver_path = get_setting("DOXA_ACCOUNT_RESOLVER")
        if not resolver_path:
            return JsonResponse({"error": "configuration_error", "message": "DOXA_ACCOUNT_RESOLVER setting is required"}, status=500)

        resolver_cls = import_string(resolver_path)
        resolver = resolver_cls()

        client = get_doxa_client()
        params = request.GET.dict()

        try:
            user = client.resolve_account(params, tx, resolver)
            if not user:
                return JsonResponse({"error": "access_denied", "message": "Account could not be resolved"}, status=403)

            login(request, user, backend="doxa_django.backend.DoxaAuthenticationBackend")
            redirect_to = get_setting("LOGIN_REDIRECT_URL", "/dashboard/")
            return HttpResponseRedirect(redirect_to)
        except Exception as e:
            cat = getattr(e, "category", "authentication_error")
            return JsonResponse({"error": cat, "message": str(e)}, status=400)
