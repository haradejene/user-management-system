import { apiClient } from "./api-client";

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

export const developersService = {
  async getSdks(): Promise<SdkMetadata[]> {
    const res = await apiClient.get<{ data: SdkMetadata[] }>("/api/developers/sdks");
    return res.data.data;
  },

  async getDiagnostics(): Promise<DiagnosticsMetadata> {
    const res = await apiClient.get<DiagnosticsMetadata>("/api/developers/diagnostics");
    return res.data;
  },

  getDownloadUrl(framework: string): string {
    const base = apiClient.defaults.baseURL ?? "http://localhost:8002";
    return `${base}/api/developers/sdks/${framework}/download`;
  },
};
