const { ApplicationAccessException } = require('./errors');

class ApplicationAccessValidator {
  /**
   * @param {string} issuer
   * @param {object} [fetchOptions]
   */
  constructor(issuer, fetchOptions = {}) {
    this.issuer = issuer.replace(/\/$/, '');
    this.fetchOptions = fetchOptions;
  }

  /**
   * Authoritatively verify active central application access.
   * @param {string} accessToken
   * @returns {Promise<boolean>}
   */
  async verify(accessToken) {
    if (!accessToken) {
      throw new ApplicationAccessException('missing_access_token', 'Access token is required for application verification');
    }

    const endpoint = `${this.issuer}/oauth/application-access`;

    let response;
    try {
      response = await fetch(endpoint, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
        ...this.fetchOptions,
      });
    } catch {
      throw new ApplicationAccessException('transient_service_failure', 'Failed to communicate with access verification service');
    }

    if (response.status !== 200) {
      throw new ApplicationAccessException('access_denied_or_insufficient_scope', 'Application access denied or token ineligible');
    }

    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
      throw new ApplicationAccessException('access_denied_or_insufficient_scope', 'Invalid response media type');
    }

    let data;
    try {
      data = await response.json();
    } catch {
      throw new ApplicationAccessException('access_denied_or_insufficient_scope', 'Unparseable response body');
    }

    // Must be exactly { effective: true } with no extra false properties
    if (data && data.effective === true) {
      return true;
    }

    throw new ApplicationAccessException('access_denied_or_insufficient_scope', 'Application access not effective');
  }
}

module.exports = {
  ApplicationAccessValidator,
};
