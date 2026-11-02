export interface SdkMetadata {
  framework: "laravel" | "node" | "django";
  name: string;
  language: string;
  runtime: string;
  package_name: string;
  supported_versions: string;
  version: string;
  install_command: string;
  download_url: string;
  contract_version: string;
  description: string;
  features: string[];
  env_template: Record<string, string>;
}

export interface ClientAppSummary {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  clients: {
    client_id: string;
    name: string;
    redirect_uris: string[];
    confidential: boolean;
  }[];
}

export interface DiagnosticsMetadata {
  issuer: string;
  status: "healthy" | "degraded";
  contract_version: string;
  endpoints: {
    discovery: string;
    jwks: string;
    authorization: string;
    token: string;
    userinfo: string;
    application_access: string;
  };
  requirements: Record<string, string>;
  applications: ClientAppSummary[];
}

export interface DeveloperCompanyOption {
  id: string;
  name: string;
}

export interface OAuthApplicationRequestInput {
  name: string;
  developer_name: string;
  developer_email: string;
  company_id?: string;
  organization?: string;
  description?: string;
  redirect_uris: string[];
  confidential?: boolean;
  requested_scopes?: string[];
}

export interface OAuthApplicationRequestDetail {
  id?: string;
  public_id?: string;
  reference_code: string;
  name: string;
  slug?: string;
  developer_name: string;
  developer_email: string;
  company_id?: string | null;
  company?: {
    id: string;
    name: string;
  } | null;
  organization?: string | null;
  description?: string | null;
  redirect_uris: string[];
  confidential: boolean;
  requested_scopes?: string[];
  status: "pending" | "approved" | "rejected";
  admin_notes?: string | null;
  created_at: string;
  reviewed_at?: string | null;
  reviewer?: {
    id: string;
    name: string;
    email: string;
  } | null;
  application?: {
    id: string;
    name: string;
    slug: string;
  } | null;
  oauth_client_id?: string | null;
  client_id?: string | null;
  client_secret?: string | null;
  issuer?: string;
  endpoints?: Record<string, string>;
}

