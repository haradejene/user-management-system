const crypto = require('crypto');

/**
 * Generate cryptographically secure random url-safe token.
 * @param {number} bytes
 * @returns {string}
 */
function randomString(bytes = 32) {
  return crypto.randomBytes(bytes).toString('base64url');
}

/**
 * Generate PKCE code_verifier and code_challenge (S256).
 * @returns {{ verifier: string, challenge: string, method: string }}
 */
function generatePkce() {
  // 64 random bytes produce an 86-character base64url string (compliant with 43-128 chars RFC 7636).
  const verifier = crypto.randomBytes(64).toString('base64url');
  const challenge = crypto.createHash('sha256').update(verifier).digest('base64url');
  return {
    verifier,
    challenge,
    method: 'S256',
  };
}

module.exports = {
  randomString,
  generatePkce,
};
