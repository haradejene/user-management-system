const { ConfigurationException } = require('./errors');

class OidcDiscovery {
  /**
   * @param {string} issuer
   * @param {number} [cacheTtlSeconds=300]
   * @param {object} [fetchOptions]
   */
  constructor(issuer, cacheTtlSeconds = 300, fetchOptions = {}) {
    if (!issuer || typeof issuer !== 'string') {
      throw new ConfigurationException('Valid issuer URL is required');
    }
    this.issuer = issuer.replace(/\/$/, '');
    this.cacheTtlMs = cacheTtlSeconds * 1000;
    this.fetchOptions = fetchOptions;
    this.metadata = null;
    this.metadataExpiresAt = 0;
    this.jwks = null;
    this.jwksExpiresAt = 0;
  }

  /**
   * Fetch OIDC configuration from discovery endpoint.
   * @returns {Promise<{
   *   issuer: string,
   *   authorization_endpoint: string,
   *   token_endpoint: string,
   *   userinfo_endpoint: string,
   *   jwks_uri: string
   * }>}
   */
  async getMetadata() {
    const now = Date.now();
    if (this.metadata && now < this.metadataExpiresAt) {
      return this.metadata;
    }

    const discoveryUrl = `${this.issuer}/.well-known/openid-configuration`;
    try {
      const response = await fetch(discoveryUrl, {
        headers: { Accept: 'application/json' },
        ...this.fetchOptions,
      });

      if (!response.ok) {
        throw new ConfigurationException(`Discovery failed with status ${response.status}`);
      }

      const data = await response.json();
      if (!data.issuer || !data.authorization_endpoint || !data.token_endpoint || !data.jwks_uri) {
        throw new ConfigurationException('Discovery document is missing required endpoints');
      }

      this.metadata = data;
      this.metadataExpiresAt = now + this.cacheTtlMs;
      return this.metadata;
    } catch (err) {
      if (err instanceof ConfigurationException) throw err;
      throw new ConfigurationException(`Failed to reach discovery endpoint: ${err.message}`);
    }
  }

  /**
   * Fetch JWKS public keys.
   * @returns {Promise<{ keys: Array<{ kid: string, kty: string, n: string, e: string }> }>}
   */
  async getJwks() {
    const now = Date.now();
    if (this.jwks && now < this.jwksExpiresAt) {
      return this.jwks;
    }

    const metadata = await this.getMetadata();
    try {
      const response = await fetch(metadata.jwks_uri, {
        headers: { Accept: 'application/json' },
        ...this.fetchOptions,
      });

      if (!response.ok) {
        throw new ConfigurationException(`JWKS failed with status ${response.status}`);
      }

      const data = await response.json();
      if (!data.keys || !Array.isArray(data.keys)) {
        throw new ConfigurationException('JWKS response missing keys array');
      }

      this.jwks = data;
      this.jwksExpiresAt = now + this.cacheTtlMs;
      return this.jwks;
    } catch (err) {
      if (err instanceof ConfigurationException) throw err;
      throw new ConfigurationException(`Failed to retrieve JWKS: ${err.message}`);
    }
  }
}

module.exports = {
  OidcDiscovery,
};
