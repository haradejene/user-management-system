/** Safe metadata returned by the application-scoped OAuth client API. */
export interface OAuthClient {
  id: string;
  name: string;
  application_id: string;
  confidential: boolean;
  revoked: boolean;
  redirect_uris: string[];
  grant_types: string[];
  created_at: string | null;
  updated_at: string | null;
  pkce_required?: boolean;
  pkce_method?: string | null;
  allowed_scopes?: string[];
  issuer?: string;
  discovery_url?: string;
}

export interface OAuthClientInput {
  name: string;
  redirect_uris: string[];
  confidential: boolean;
}

/** Creation only. Never reuse this response as list/detail state. */
export interface OAuthClientCreation {
  data: OAuthClient;
  client_secret?: string;
}
