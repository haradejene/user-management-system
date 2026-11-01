const { TokenException } = require('./errors');

class CodeExchange {
  /**
   * @param {import('./discovery').OidcDiscovery} discovery
   * @param {string} clientId
   * @param {string|null} [clientSecret]
   * @param {string} redirectUri
   * @param {object} [fetchOptions]
   */
  constructor(discovery, clientId, clientSecret, redirectUri, fetchOptions = {}) {
    this.discovery = discovery;
    this.clientId = clientId;
    this.clientSecret = clientSecret || null;
    this.redirectUri = redirectUri;
    this.fetchOptions = fetchOptions;
  }

  /**
   * Exchange authorization code for tokens.
   * @param {string} code
   * @param {string} codeVerifier
   * @returns {Promise<{
   *   accessToken: string,
   *   idToken: string,
   *   refreshToken: string|null,
   *   expiresIn: number,
   *   tokenType: string,
   *   raw: object
   * }>}
   */
  async exchange(code, codeVerifier) {
    if (!code) {
      throw new TokenException('invalid_code', 'Authorization code is required');
    }
    if (!codeVerifier) {
      throw new TokenException('invalid_verifier', 'PKCE code_verifier is required');
    }

    const metadata = await this.discovery.getMetadata();
    const tokenUrl = metadata.token_endpoint;

    const params = new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: this.clientId,
      code,
      redirect_uri: this.redirectUri,
      code_verifier: codeVerifier,
    });

    if (this.clientSecret) {
      params.append('client_secret', this.clientSecret);
    }

    try {
      const response = await fetch(tokenUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          Accept: 'application/json',
        },
        body: params.toString(),
        ...this.fetchOptions,
      });

      const body = await response.json();
      if (!response.ok) {
        throw new TokenException(body.error || 'token_exchange_failed', body.error_description || 'Failed to exchange authorization code');
      }

      if (!body.access_token) {
        throw new TokenException('invalid_response', 'Token response missing access_token');
      }
      if (!body.id_token) {
        throw new TokenException('invalid_response', 'Token response missing id_token (openid scope required)');
      }

      return {
        accessToken: body.access_token,
        idToken: body.id_token,
        refreshToken: body.refresh_token || null,
        expiresIn: body.expires_in || 0,
        tokenType: body.token_type || 'Bearer',
        raw: body,
      };
    } catch (err) {
      if (err instanceof TokenException) throw err;
      throw new TokenException('network_error', `Token exchange transport failure: ${err.message}`);
    }
  }
}

module.exports = {
  CodeExchange,
};
