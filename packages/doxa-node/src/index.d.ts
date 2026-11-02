export interface DoxaConfig {
  issuer: string;
  clientId: string;
  clientSecret?: string | null;
  redirectUri: string;
  scopes?: string[];
  fetchOptions?: RequestInit;
}

export interface AuthorizationOptions {
  prompt?: 'none' | 'login' | 'consent' | 'select_account';
  loginHint?: string;
}

export interface LoginTransaction {
  url: string;
  state: string;
  nonce: string;
  codeVerifier: string;
}

export interface CallbackParams {
  code?: string;
  state?: string;
  error?: string;
  error_description?: string;
}

export interface ExpectedTransaction {
  state: string;
  nonce: string;
  codeVerifier: string;
}

export interface AccountResolver<T = any> {
  resolve(identity: DoxaIdentity): Promise<T> | T;
}

export class DoxaIdentity {
  readonly issuer: string;
  readonly subject: string;
  readonly email: string;
  readonly name: string;
  readonly emailVerified: boolean;
  readonly claims: Readonly<Record<string, any>>;
  constructor(params: {
    issuer: string;
    subject: string;
    email?: string;
    name?: string;
    emailVerified?: boolean;
    claims?: Record<string, any>;
  });
}

export class DoxaClient {
  readonly issuer: string;
  readonly clientId: string;
  readonly clientSecret: string | null;
  readonly redirectUri: string;
  readonly scopes: string[];

  constructor(config: DoxaConfig);
  beginLogin(options?: AuthorizationOptions): Promise<LoginTransaction>;
  handleAuthorizedCallback(
    callbackParams: CallbackParams,
    expectedTransaction: ExpectedTransaction
  ): Promise<DoxaIdentity>;
  resolveAccount<T = any>(
    callbackParams: CallbackParams,
    expectedTransaction: ExpectedTransaction,
    resolver: AccountResolver<T>
  ): Promise<T>;
}

export class DoxaException extends Error {
  readonly category: string;
  constructor(category: string, message?: string);
}

export class ConfigurationException extends DoxaException {}
export class AuthorizationException extends DoxaException {}
export class ApplicationAccessException extends DoxaException {}
export class TokenException extends DoxaException {}

export function generatePkce(): { verifier: string; challenge: string; method: string };
export function randomString(bytes?: number): string;
export function createExpressAuth(client: DoxaClient, sessionKey?: string): any;
export function createNextAuth(client: DoxaClient, cookieName?: string): any;
