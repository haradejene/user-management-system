import { apiClient } from "./api-client";

export * from "@/types/developers";
import type {
  DeveloperCompanyOption,
  DiagnosticsMetadata,
  OAuthApplicationRequestDetail,
  OAuthApplicationRequestInput,
  SdkMetadata,
} from "@/types/developers";

export const developersService = {
  async getSdks(): Promise<SdkMetadata[]> {
    const res = await apiClient.get<{ data: SdkMetadata[] }>("/api/developers/sdks");
    return res.data.data;
  },

  async getCompanies(): Promise<DeveloperCompanyOption[]> {
    const res = await apiClient.get<{ data: DeveloperCompanyOption[] }>("/api/developers/companies");
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

  async submitOAuthRequest(
    input: OAuthApplicationRequestInput
  ): Promise<{ message: string; data: OAuthApplicationRequestDetail }> {
    try {
      await apiClient.get("/sanctum/csrf-cookie");
    } catch {
      // CSRF cookie lookup can fail silently in tests or decoupled hosts
    }
    const res = await apiClient.post<{ message: string; data: OAuthApplicationRequestDetail }>(
      "/api/developers/oauth-requests",
      input
    );
    return res.data;
  },

  async getOAuthRequestStatus(reference: string): Promise<OAuthApplicationRequestDetail> {
    const res = await apiClient.get<{ data: OAuthApplicationRequestDetail }>(
      `/api/developers/oauth-requests/${encodeURIComponent(reference.trim())}`
    );
    return res.data.data;
  },
};
