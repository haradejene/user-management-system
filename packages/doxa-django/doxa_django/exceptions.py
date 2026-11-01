"""Doxa SDK Exceptions hierarchy."""


class DoxaException(Exception):
    """Base exception for all Doxa SDK errors."""

    def __init__(self, category: str, message: str = ""):
        super().__init__(message or category)
        self.category = category


class ConfigurationException(DoxaException):
    def __init__(self, message: str = "configuration_error"):
        super().__init__("configuration_error", message)


class AuthorizationException(DoxaException):
    def __init__(self, category: str = "authorization_error", message: str = ""):
        super().__init__(category, message)


class ApplicationAccessException(DoxaException):
    def __init__(self, category: str = "access_denied_or_insufficient_scope", message: str = ""):
        super().__init__(category, message)


class TokenException(DoxaException):
    def __init__(self, category: str = "token_error", message: str = ""):
        super().__init__(category, message)
