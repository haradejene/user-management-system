'use strict';

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('crypto');
const {
  DoxaClient,
  DoxaIdentity,
  generatePkce,
  randomString,
  ApplicationAccessException,
  AuthorizationException,
  ConfigurationException,
} = require('../src/index');

describe('@doxa/auth-node SDK Test Suite', () => {
  test('generatePkce() produces RFC 7636 compliant verifier and S256 challenge', () => {
    const pkce = generatePkce();
    assert.equal(pkce.method, 'S256');
    assert.ok(pkce.verifier.length >= 43 && pkce.verifier.length <= 128);

    // Verify SHA-256 derivation
    const expected = crypto.createHash('sha256').update(pkce.verifier).digest('base64url');
    assert.equal(pkce.challenge, expected);
  });

  test('randomString() produces high-entropy base64url strings', () => {
    const s1 = randomString(32);
    const s2 = randomString(32);
    assert.notEqual(s1, s2);
    assert.ok(s1.length >= 40);
  });

  test('DoxaConfig validation rejects missing required parameters', () => {
    assert.throws(() => new DoxaClient({}), ConfigurationException);
    assert.throws(() => new DoxaClient({ issuer: 'https://iam.example.com' }), ConfigurationException);
  });

  test('beginLogin() constructs compliant authorization URL with PKCE and state', async () => {
    const client = new DoxaClient({
      issuer: 'https://iam.example.com',
      clientId: 'test-client-uuid',
      redirectUri: 'https://app.example.com/callback',
      scopes: ['openid', 'profile'],
    });

    // Mock discovery metadata
    client.discovery.getMetadata = async () => ({
      issuer: 'https://iam.example.com',
      authorization_endpoint: 'https://iam.example.com/oauth/authorize',
      token_endpoint: 'https://iam.example.com/oauth/token',
      jwks_uri: 'https://iam.example.com/oauth/jwks',
    });

    const tx = await client.beginLogin({ prompt: 'consent' });
    assert.ok(tx.url.startsWith('https://iam.example.com/oauth/authorize?'));

    const parsed = new URL(tx.url);
    assert.equal(parsed.searchParams.get('client_id'), 'test-client-uuid');
    assert.equal(parsed.searchParams.get('redirect_uri'), 'https://app.example.com/callback');
    assert.equal(parsed.searchParams.get('response_type'), 'code');
    assert.equal(parsed.searchParams.get('code_challenge_method'), 'S256');
    assert.equal(parsed.searchParams.get('prompt'), 'consent');
    assert.equal(parsed.searchParams.get('state'), tx.state);
    assert.equal(parsed.searchParams.get('nonce'), tx.nonce);
  });

  test('DoxaIdentity is deeply immutable and contains expected fields', () => {
    const identity = new DoxaIdentity({
      issuer: 'https://iam.example.com',
      subject: 'user-uuid-123',
      email: 'user@example.com',
      name: 'Test User',
      emailVerified: true,
      claims: { role: 'admin' },
    });

    assert.equal(identity.issuer, 'https://iam.example.com');
    assert.equal(identity.subject, 'user-uuid-123');
    assert.equal(identity.email, 'user@example.com');
    assert.equal(identity.emailVerified, true);

    // Verify immutability
    assert.throws(() => { identity.subject = 'new-id'; });
    assert.throws(() => { identity.claims.role = 'user'; });
  });

  test('resolveAccount() executes token exchange, access check, and delegates to resolver', async () => {
    const client = new DoxaClient({
      issuer: 'https://iam.example.com',
      clientId: 'test-client-uuid',
      redirectUri: 'https://app.example.com/callback',
    });

    // Mock dependencies
    client.exchange.exchange = async (code, verifier) => {
      assert.equal(code, 'auth-code-123');
      assert.equal(verifier, 'verifier-456');
      return { accessToken: 'mock-access', idToken: 'mock-id' };
    };

    client.validator.validate = async (idToken, nonce) => {
      assert.equal(nonce, 'nonce-789');
      return {
        sub: '01a-user-sub',
        email: 'dev@example.test',
        name: 'Dev User',
        email_verified: true,
      };
    };

    let accessChecked = false;
    client.access.verify = async (token) => {
      assert.equal(token, 'mock-access');
      accessChecked = true;
      return true;
    };

    const resolver = {
      calls: 0,
      async resolve(identity) {
        this.calls++;
        return { localId: 99, email: identity.email };
      },
    };

    const result = await client.resolveAccount(
      { code: 'auth-code-123', state: 'state-abc' },
      { state: 'state-abc', nonce: 'nonce-789', codeVerifier: 'verifier-456' },
      resolver
    );

    assert.equal(accessChecked, true);
    assert.equal(resolver.calls, 1);
    assert.deepEqual(result, { localId: 99, email: 'dev@example.test' });
  });

  test('resolveAccount() fails closed if application access verification fails', async () => {
    const client = new DoxaClient({
      issuer: 'https://iam.example.com',
      clientId: 'test-client-uuid',
      redirectUri: 'https://app.example.com/callback',
    });

    client.exchange.exchange = async () => ({ accessToken: 'bad-access', idToken: 'id-token' });
    client.validator.validate = async () => ({ sub: 'user-sub' });
    client.access.verify = async () => {
      throw new ApplicationAccessException('access_denied_or_insufficient_scope');
    };

    const resolver = {
      called: false,
      resolve() { this.called = true; },
    };

    await assert.rejects(
      async () => client.resolveAccount(
        { code: 'code', state: 's' },
        { state: 's', nonce: 'n', codeVerifier: 'v' },
        resolver
      ),
      ApplicationAccessException
    );

    assert.equal(resolver.called, false);
  });

  test('createNextAuth() handles login redirection and callback execution', async () => {
    const { createNextAuth } = require('../src/adapters/next');
    const client = new DoxaClient({
      issuer: 'https://iam.example.com',
      clientId: 'test-client-uuid',
      redirectUri: 'https://app.example.com/callback',
    });

    client.discovery.getMetadata = async () => ({
      authorization_endpoint: 'https://iam.example.com/oauth/authorize',
      token_endpoint: 'https://iam.example.com/oauth/token',
      jwks_uri: 'https://iam.example.com/oauth/jwks',
    });

    const nextAuth = createNextAuth(client);
    const mockReq = {
      headers: new Headers({ 'x-forwarded-proto': 'https' }),
      url: 'https://app.example.com/auth/login',
    };

    const loginRes = await nextAuth.handleLogin(mockReq);
    assert.equal(loginRes.status, 302);
    assert.ok(loginRes.headers.get('Location').includes('https://iam.example.com/oauth/authorize'));
    assert.ok(loginRes.headers.get('Set-Cookie').includes('doxa_tx='));
  });
});

