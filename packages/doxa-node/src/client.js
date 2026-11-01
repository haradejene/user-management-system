const { OidcDiscovery } = require('./discovery');
const { IdTokenValidator } = require('./validator');
const { CodeExchange } = require('./exchange');
const { ApplicationAccessValidator } = require('./access');
const { generatePkce, randomString } = require('./crypto');
const { ConfigurationException, AuthorizationException } = require('./errors');

/**
 * Normalized immutable identity returned from Doxa IAM.
 */
class DoxaIdentity {
  #issuer;
  #subject;
  #email;
  #name;
  #emailVerified;
  #claims;

  /**
   * @param {object} params
   * @param {string} params.issuer
   * @param {string} params.subject
   * @param {string} [params.email]
   * @param {string} [params.name]
   * @param {boolean} [params.emailVerified]
   * @param {object} [params.claims]
   */
  constructor({ issuer, subject, email = '', name = '', emailVerified = false, claims = {} }) {
    this.#issuer = issuer;
    this.#subject = subject;
    this.#email = email;
    this.#name = name;
    this.#emailVerified = Boolean(emailVerified);
    this.#claims = Object.freeze({ ...claims });
    Object.freeze(this);
  }

  get issuer() { return this.#issuer; }
  get subject() { return this.#subject; }
  get email() { return this.#email; }
  get name() { return this.#name; }
  get emailVerified() { return this.#emailVerified; }
  get claims() { return this.#claims; }

  toJSON() {
    return {
      issuer: this.#issuer,
      subject: this.#subject,
      email: this.#email,
      name: this.#name,
      emailVerified: this.#emailVerified,
      claims: this.#claims,
    };
  }
}

class DoxaClient {
  /**
   * @param {object} config
   * @param {string} config.issuer - Issuer URL of Central IAM
   * @param {string} config.clientId - Registered OAuth client UUID
   * @param {string} [config.clientSecret] - Confidential client secret
   * @param {string} config.redirectUri - Exact registered callback URI
   * @param {string[]} [config.scopes=['openid','profile','email']] - Requested scopes
   * @param {object} [config.fetchOptions] - Custom fetch/TLS options
   */
  constructor(config) {
    if (!config.issuer || !config.clientId || !config.redirectUri) {
      throw new ConfigurationException('issuer, clientId, and redirectUri are required');
    }

    this.issuer = config.issuer.replace(/\/$/, '');
    this.clientId = config.clientId;
    this.clientSecret = config.clientSecret || null;
    this.redirectUri = config.redirectUri;
    this.scopes = Array.isArray(config.scopes) && config.scopes.length > 0
      ? config.scopes
      : ['openid', 'profile', 'email'];
    this.fetchOptions = config.fetchOptions || {};

    this.discovery = new OidcDiscovery(this.issuer, 300, this.fetchOptions);
    this.validator = new IdTokenValidator(this.discovery, this.issuer, this.clientId);
    this.exchange = new CodeExchange(this.discovery, this.clientId, this.clientSecret, this.redirectUri, this.fetchOptions);
    this.access = new ApplicationAccessValidator(this.issuer, this.fetchOptions);
  }

  /**
   * Generate state, nonce, PKCE, and authorization redirect URL.
   * Store state, nonce, and codeVerifier in session/cookie to verify on callback.
   * @param {object} [options]
   * @param {string} [options.prompt]
   * @param {string} [options.loginHint]
   * @returns {Promise<{
   *   url: string,
   *   state: string,
   *   nonce: string,
   *   codeVerifier: string
   * }>}
   */
  async beginLogin(options = {}) {
    const metadata = await this.discovery.getMetadata();
    const pkce = generatePkce();
    const state = randomString(32);
    const nonce = randomString(32);

    const params = new URLSearchParams({
      response_type: 'code',
      client_id: this.clientId,
      redirect_uri: this.redirectUri,
      scope: this.scopes.join(' '),
      state,
      nonce,
      code_challenge: pkce.challenge,
      code_challenge_method: pkce.method,
    });

    if (options.prompt) params.append('prompt', options.prompt);
    if (options.loginHint) params.append('login_hint', options.loginHint);

    const url = `${metadata.authorization_endpoint}?${params.toString()}`;
    return {
      url,
      state,
      nonce,
      codeVerifier: pkce.verifier,
    };
  }

  /**
   * Process incoming callback, validate OIDC tokens, and enforce application access.
   * @param {{ code?: string, state?: string, error?: string, error_description?: string }} callbackParams
   * @param {{ state: string, nonce: string, codeVerifier: string }} expectedTransaction
   * @returns {Promise<DoxaIdentity>}
   */
  async handleAuthorizedCallback(callbackParams, expectedTransaction) {
    if (callbackParams.error) {
      throw new AuthorizationException(callbackParams.error, callbackParams.error_description || 'Authorization was denied by provider');
    }

    if (!callbackParams.code || !callbackParams.state) {
      throw new AuthorizationException('invalid_request', 'Callback parameters missing code or state');
    }

    if (!expectedTransaction || !expectedTransaction.state || callbackParams.state !== expectedTransaction.state) {
      throw new AuthorizationException('state_mismatch', 'Callback state parameter does not match initiated transaction');
    }

    // 1. Exchange authorization code for tokens using PKCE verifier
    const tokens = await this.exchange.exchange(callbackParams.code, expectedTransaction.codeVerifier);

    // 2. Cryptographically validate ID Token signature & claims
    const claims = await this.validator.validate(tokens.idToken, expectedTransaction.nonce);

    // 3. Authoritatively verify active central application access
    await this.access.verify(tokens.accessToken);

    return new DoxaIdentity({
      issuer: this.issuer,
      subject: claims.sub,
      email: claims.email || '',
      name: claims.name || '',
      emailVerified: Boolean(claims.email_verified),
      claims,
    });
  }

  /**
   * Complete OIDC authentication, enforce access, and resolve to local host account.
   * @template T
   * @param {{ code?: string, state?: string, error?: string }} callbackParams
   * @param {{ state: string, nonce: string, codeVerifier: string }} expectedTransaction
   * @param {{ resolve: (identity: DoxaIdentity) => Promise<T>|T }} resolver
   * @returns {Promise<T>}
   */
  async resolveAccount(callbackParams, expectedTransaction, resolver) {
    if (!resolver || typeof resolver.resolve !== 'function') {
      throw new ConfigurationException('AccountResolver with resolve() method is required');
    }

    const identity = await this.handleAuthorizedCallback(callbackParams, expectedTransaction);
    return await resolver.resolve(identity);
  }
}

module.exports = {
  DoxaClient,
  DoxaIdentity,
};
