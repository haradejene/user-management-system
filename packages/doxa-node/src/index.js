const { DoxaClient, DoxaIdentity } = require('./client');
const {
  DoxaException,
  ConfigurationException,
  AuthorizationException,
  ApplicationAccessException,
  TokenException,
} = require('./errors');
const { OidcDiscovery } = require('./discovery');
const { IdTokenValidator } = require('./validator');
const { CodeExchange } = require('./exchange');
const { ApplicationAccessValidator } = require('./access');
const { generatePkce, randomString } = require('./crypto');
const { createExpressAuth } = require('./adapters/express');

module.exports = {
  DoxaClient,
  DoxaIdentity,
  DoxaException,
  ConfigurationException,
  AuthorizationException,
  ApplicationAccessException,
  TokenException,
  OidcDiscovery,
  IdTokenValidator,
  CodeExchange,
  ApplicationAccessValidator,
  generatePkce,
  randomString,
  createExpressAuth,
};
