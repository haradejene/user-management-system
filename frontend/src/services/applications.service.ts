import { apiClient } from "@/services/api-client";
import type { ApiResponse, PaginatedResponse } from "@/types/api";
import type { Application, ApplicationAccess, ApplicationStatus, ApplicationUser } from "@/types/application";
import type { OAuthClient, OAuthClientCreation, OAuthClientInput } from "@/types/oauth-client";

export interface ApplicationInput { name: string; slug: string; description?: string | null }

export const applicationsService = {
  async updateOAuthRedirects(application: string, client: string, redirect_uris: string[], updated_at: string | null): Promise<OAuthClient> {
    const response = await apiClient.patch<ApiResponse<OAuthClient>>(`/api/admin/applications/${application}/oauth-clients/${client}/redirect-uris`, { redirect_uris, updated_at });
    return response.data.data;
  },
  async oauthClients(application: string, page = 1, perPage = 25): Promise<PaginatedResponse<OAuthClient>> {
    const response = await apiClient.get<PaginatedResponse<OAuthClient>>(`/api/admin/applications/${application}/oauth-clients`, { params: { page: Math.max(1, Math.floor(page)), per_page: Math.min(100, Math.max(1, Math.floor(perPage))) } });
    return response.data;
  },
  async oauthClient(application: string, client: string): Promise<OAuthClient> {
    const response = await apiClient.get<ApiResponse<OAuthClient>>(`/api/admin/applications/${application}/oauth-clients/${client}`);
    return response.data.data;
  },
  async createOAuthClient(application: string, input: OAuthClientInput): Promise<OAuthClientCreation> {
    const response = await apiClient.post<OAuthClientCreation>(`/api/admin/applications/${application}/oauth-clients`, { name: input.name, redirect_uris: input.redirect_uris, confidential: input.confidential });
    return response.data;
  },
  async revokeOAuthClient(application: string, client: string): Promise<void> {
    await apiClient.patch(`/api/admin/applications/${application}/oauth-clients/${client}/revoke`);
  },
  async list(search = "", page = 1, perPage = 100, status?: ApplicationStatus): Promise<PaginatedResponse<Application>> {
    const response = await apiClient.get<PaginatedResponse<Application>>("/api/admin/applications", { params: { search: search || undefined, page, per_page: perPage, status } });
    return response.data;
  },
  async get(id: string): Promise<Application> {
    const response = await apiClient.get<ApiResponse<Application>>(`/api/admin/applications/${id}`);
    return response.data.data;
  },
  async create(input: ApplicationInput): Promise<Application> {
    const response = await apiClient.post<ApiResponse<Application>>("/api/admin/applications", input);
    return response.data.data;
  },
  async update(id: string, input: ApplicationInput): Promise<Application> {
    const response = await apiClient.patch<ApiResponse<Application>>(`/api/admin/applications/${id}`, input);
    return response.data.data;
  },
  async changeStatus(id: string, action: "activate" | "deactivate"): Promise<Application> {
    const response = await apiClient.patch<ApiResponse<Application>>(`/api/admin/applications/${id}/${action}`);
    return response.data.data;
  },
  async forUser(userId: string, page = 1, perPage = 15, applicationIds?: string[]): Promise<PaginatedResponse<ApplicationAccess>> {
    const response = await apiClient.get<PaginatedResponse<ApplicationAccess>>(`/api/admin/users/${userId}/applications`, { params: { page, per_page: perPage, application_ids: applicationIds } });
    return response.data;
  },
  async users(applicationId: string, page = 1, perPage = 15): Promise<PaginatedResponse<ApplicationUser>> {
    const response = await apiClient.get<PaginatedResponse<ApplicationUser>>(`/api/admin/applications/${applicationId}/users`, { params: { page: Math.max(1, Math.floor(page)), per_page: Math.min(100, Math.max(1, Math.floor(perPage))) } });
    return response.data;
  },
  async grant(userId: string, applicationId: string): Promise<ApplicationAccess> {
    const response = await apiClient.post<ApiResponse<ApplicationAccess>>(`/api/admin/users/${userId}/applications`, { application_id: applicationId });
    return response.data.data;
  },
  async revoke(userId: string, applicationId: string): Promise<void> {
    await apiClient.delete(`/api/admin/users/${userId}/applications/${applicationId}`);
  },
};
