"""Unit tests for doxa-django SDK."""

import unittest
from unittest.mock import MagicMock
from doxa_django import (
    DoxaClient,
    DoxaIdentity,
    AccountResolver,
    generate_pkce,
    random_string,
    ConfigurationException,
    ApplicationAccessException,
)


class TestDoxaDjangoSDK(unittest.TestCase):
    def test_generate_pkce(self):
        pkce = generate_pkce()
        self.assertEqual(pkce["method"], "S256")
        self.assertTrue(43 <= len(pkce["verifier"]) <= 128)
        self.assertTrue(len(pkce["challenge"]) > 30)

    def test_random_string(self):
        s1 = random_string(32)
        s2 = random_string(32)
        self.assertNotEqual(s1, s2)
        self.assertTrue(len(s1) >= 40)

    def test_config_validation(self):
        with self.assertRaises(ConfigurationException):
            DoxaClient(issuer="", client_id="cid", redirect_uri="http://uri")

    def test_begin_login_generates_compliant_url(self):
        client = DoxaClient(
            issuer="https://iam.example.com",
            client_id="my-client-uuid",
            redirect_uri="https://app.example.com/callback",
            scopes=["openid", "profile"],
        )
        client.discovery.get_metadata = MagicMock(return_value={
            "issuer": "https://iam.example.com",
            "authorization_endpoint": "https://iam.example.com/oauth/authorize",
            "token_endpoint": "https://iam.example.com/oauth/token",
            "jwks_uri": "https://iam.example.com/oauth/jwks",
        })

        tx = client.begin_login({"prompt": "consent"})
        self.assertTrue(tx["url"].startswith("https://iam.example.com/oauth/authorize?"))
        self.assertIn("client_id=my-client-uuid", tx["url"])
        self.assertIn("response_type=code", tx["url"])
        self.assertIn("code_challenge_method=S256", tx["url"])
        self.assertIn(f"state={tx['state']}", tx["url"])
        self.assertIn(f"nonce={tx['nonce']}", tx["url"])

    def test_doxa_identity_immutability(self):
        identity = DoxaIdentity(
            issuer="https://iam.example.com",
            subject="sub-123",
            email="user@example.com",
            name="Test User",
        )
        self.assertEqual(identity.subject, "sub-123")
        with self.assertRaises(Exception):
            identity.subject = "new-subject"

    def test_resolve_account_success(self):
        client = DoxaClient(
            issuer="https://iam.example.com",
            client_id="cid",
            redirect_uri="http://callback",
        )
        client.exchange_code = MagicMock(return_value={
            "access_token": "access-123",
            "id_token": "id-token-abc",
        })
        client.validator.validate = MagicMock(return_value={
            "sub": "sub-uuid-456",
            "email": "django@example.test",
            "name": "Django Developer",
            "email_verified": True,
        })
        client.access.verify = MagicMock(return_value=True)

        class LocalResolver(AccountResolver):
            def __init__(self):
                self.calls = 0

            def resolve(self, identity):
                self.calls += 1
                return {"id": 101, "email": identity.email, "sub": identity.subject}

        resolver = LocalResolver()
        user = client.resolve_account(
            {"code": "auth-code", "state": "state-123"},
            {"state": "state-123", "nonce": "nonce-456", "code_verifier": "ver-789"},
            resolver,
        )

        self.assertEqual(resolver.calls, 1)
        self.assertEqual(user["id"], 101)
        self.assertEqual(user["email"], "django@example.test")

    def test_resolve_account_fails_closed_when_access_denied(self):
        client = DoxaClient(
            issuer="https://iam.example.com",
            client_id="cid",
            redirect_uri="http://callback",
        )
        client.exchange_code = MagicMock(return_value={
            "access_token": "access-123",
            "id_token": "id-token",
        })
        client.validator.validate = MagicMock(return_value={"sub": "sub-123"})
        client.access.verify = MagicMock(side_effect=ApplicationAccessException("access_denied"))

        class LocalResolver(AccountResolver):
            def resolve(self, identity):
                return {"id": 1}

        with self.assertRaises(ApplicationAccessException):
            client.resolve_account(
                {"code": "code", "state": "s"},
                {"state": "s", "nonce": "n", "code_verifier": "v"},
                LocalResolver(),
            )


if __name__ == "__main__":
    unittest.main()
