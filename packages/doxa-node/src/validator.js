const crypto = require('crypto');
const { AuthorizationException } = require('./errors');

class IdTokenValidator {
  /**
   * @param {import('./discovery').OidcDiscovery} discovery
   * @param {string} issuer
   * @param {string} clientId
   * @param {number} [clockToleranceSeconds=60]
   */
  constructor(discovery, issuer, clientId, clockToleranceSeconds = 60) {
    this.discovery = discovery;
    this.issuer = issuer.replace(/\/$/, '');
    this.clientId = clientId;
    this.clockToleranceSeconds = clockToleranceSeconds;
  }

  /**
   * Validate raw JWT ID token and verify claims against transaction.
   * @param {string} rawToken
   * @param {string} expectedNonce
   * @returns {Promise<object>} Parsed and verified claims
   */
  async validate(rawToken, expectedNonce) {
    if (!rawToken || typeof rawToken !== 'string') {
      throw new AuthorizationException('invalid_id_token', 'ID token is missing or malformed');
    }

    const parts = rawToken.split('.');
    if (parts.length !== 3) {
      throw new AuthorizationException('invalid_id_token', 'ID token does not have 3 segments');
    }

    const [headerB64, payloadB64, signatureB64] = parts;

    let header;
    let payload;
    try {
      header = JSON.parse(Buffer.from(headerB64, 'base64url').toString('utf8'));
      payload = JSON.parse(Buffer.from(payloadB64, 'base64url').toString('utf8'));
    } catch {
      throw new AuthorizationException('invalid_id_token', 'Failed to decode token JSON');
    }

    if (header.alg !== 'RS256') {
      throw new AuthorizationException('unsupported_algorithm', `Unsupported algorithm: ${header.alg}`);
    }

    // Retrieve matching key from JWKS
    const jwks = await this.discovery.getJwks();
    const key = jwks.keys.find((k) => (!header.kid || k.kid === header.kid) && k.kty === 'RSA');
    if (!key) {
      throw new AuthorizationException('missing_signing_key', 'Matching JWK public key not found');
    }

    let publicKey;
    try {
      publicKey = crypto.createPublicKey({ key, format: 'jwk' });
    } catch (err) {
      throw new AuthorizationException('invalid_signing_key', `Failed to construct public key: ${err.message}`);
    }

    const signedData = Buffer.from(`${headerB64}.${payloadB64}`);
    const signature = Buffer.from(signatureB64, 'base64url');
    const isValid = crypto.verify('RSA-SHA256', signedData, publicKey, signature);

    if (!isValid) {
      throw new AuthorizationException('invalid_signature', 'ID token cryptographic signature verification failed');
    }

    // Validate claims
    const now = Math.floor(Date.now() / 1000);
    const tolerance = this.clockToleranceSeconds;

    // Verify issuer (exact match without trailing slash)
    const tokenIssuer = (payload.iss || '').replace(/\/$/, '');
    if (tokenIssuer !== this.issuer) {
      throw new AuthorizationException('invalid_issuer', `Issuer mismatch: expected ${this.issuer}, got ${payload.iss}`);
    }

    // Verify audience
    const aud = Array.isArray(payload.aud) ? payload.aud : [payload.aud];
    if (!aud.includes(this.clientId)) {
      throw new AuthorizationException('invalid_audience', 'Audience mismatch');
    }

    // Verify expiration
    if (typeof payload.exp !== 'number' || payload.exp + tolerance < now) {
      throw new AuthorizationException('expired_token', 'ID token has expired');
    }

    // Verify nonce
    if (expectedNonce && payload.nonce !== expectedNonce) {
      throw new AuthorizationException('invalid_nonce', 'Nonce mismatch');
    }

    // Verify subject
    if (!payload.sub || typeof payload.sub !== 'string') {
      throw new AuthorizationException('missing_subject', 'ID token missing sub claim');
    }

    return payload;
  }
}

module.exports = {
  IdTokenValidator,
};
