/**
 * Base exception for all Doxa errors.
 */
class DoxaException extends Error {
  /**
   * @param {string} category
   * @param {string} [message]
   */
  constructor(category, message) {
    super(message || category);
    this.name = this.constructor.name;
    this.category = category;
  }
}

class ConfigurationException extends DoxaException {
  constructor(message = 'configuration_error') {
    super('configuration_error', message);
  }
}

class AuthorizationException extends DoxaException {
  constructor(category = 'authorization_error', message) {
    super(category, message);
  }
}

class ApplicationAccessException extends DoxaException {
  constructor(category = 'access_denied_or_insufficient_scope', message) {
    super(category, message);
  }
}

class TokenException extends DoxaException {
  constructor(category = 'token_error', message) {
    super(category, message);
  }
}

module.exports = {
  DoxaException,
  ConfigurationException,
  AuthorizationException,
  ApplicationAccessException,
  TokenException,
};
