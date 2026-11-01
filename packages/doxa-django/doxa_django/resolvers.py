"""Abstract base class for host account resolution."""

from abc import ABC, abstractmethod
from typing import Any, Optional
from doxa_django.identity import DoxaIdentity


class AccountResolver(ABC):
    """Host policy interface. Central IAM proves identity; the host application
    owns user mapping, local permissions, roles, and local session creation."""

    @abstractmethod
    def resolve(self, identity: DoxaIdentity) -> Optional[Any]:
        """Resolve a verified Doxa identity to an authenticatable host user object."""
        raise NotImplementedError
